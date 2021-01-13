<?php

namespace Stanford\ExportRepeatingData;
/** @var ExportRepeatingData $module */
use REDCap;
use Exception;

/**
 * Class Export
 * @package Stanford\ExportRepeatingData
 *
 */
class Export
{

    private $Proj;
    private $instrumentMetadata;
    private $tempFileConfig;
    private $tempFileDate;
    private $tempFilePath;
    private $tempFileJSON;
    private $daysToCache;
    private $useTempFile = false;

    function __construct($project, $instrumentMetadata, $days, $tempFileConfig)
    {
        $this->Proj = $project;
        $this->instrumentMetadata = $instrumentMetadata;
        $this->setTempFileConfig(json_decode($tempFileConfig, true));
        $this->setDaysToCache($days);
    }

    public function checkTempFile($json)
    {
        $days = $this->getDaysToCache();
        $config = $this->getTempFileConfig();
        $found = false;
        foreach ($config as $index => $item) {
            if ($item['json'] == $json) {
                $found = true;
                $this->setTempFileJSON($json);
                if ($item['date'] && time() < strtotime($item['date'])) {
                    $this->setUseTempFile(true);
                    $this->setTempFilePath($item['path']);
                    $this->setTempFileDate($item['date']);
                } else {
                    // just update existing date and path with new informaiton.
                    $this->setTempFilePath(APP_PATH_TEMP . date("YmdHis", strtotime("+$days days")) . '_' . strtolower($this->generateRandomString()) . '_report' . '.csv');
                    $this->setTempFileDate($this->formatTempDate($days));
                    $config[$index]['path'] = $this->getTempFilePath();
                    $config[$index]['date'] = $this->getTempFileDate();
                }
            }
        }

        if (!$found) {
            $this->setTempFilePath(APP_PATH_TEMP . date("YmdHis", strtotime("+$days days")) . '_' . strtolower($this->generateRandomString()) . '_report' . '.csv');
            $this->setTempFileDate($this->formatTempDate($days));
            $this->setTempFileJSON($json);
            $config[] = array('json' => $json, 'path' => $this->getTempFilePath(), 'date' => $this->getTempFileDate());
        }
        $this->setTempFileConfig(json_encode($config));
    }

    public function formatTempDate($days)
    {
        return date('Y-m-d H:i:s', strtotime("+$days days"));
    }

    function buildAndRunQuery($config)
    {
        try {
            $newConfig = $this->assembleSpecification($config);
            $result = $this->runQuery($newConfig);
            return $result;
        } catch (Exception $e) {
            $result["status"] = 0 ;
            $result["message"] = $e->getMessage() ;
            return $result ;
        }
    }

    public function saveTempFile($content)
    {
        return file_put_contents($this->getTempFilePath(), $content);
    }

    function nvl(&$var, $default = "")
    {
        return isset($var) && strlen($var) > 0 ? $var
            : $default;
    }

    function assembleSpecification($config)
    {
        global $module;

        // oddly enough this operation is not idempotent. the incoming arrays get converted into objects
        $json_inp = json_decode(json_encode($config));

//        $module->emDebug("Input JSON :" . print_r($json_inp, TRUE));

        $json = json_decode('{ "forms" : []}');
        $json->raw_or_label = $json_inp->raw_or_label;
        // stash the preview setting, the SQL generation step needs to know
        $json->preview = $json_inp->preview;  // $config['preview'];
        $json->record_count = $json_inp->record_count;


        // look through the incoming spec for the primary-repeating form, since that will be the join key
        // used by all other repeating-date-pivot references.
        if (is_array($json_inp->cardinality) || is_object($json_inp->cardinality)) {
            foreach ($json_inp->cardinality as $instrument => $value) {
                if ($value->join === 'repeating-primary') {
                    $primaryJoinInstrument = $instrument;
                    $meta = $this->instrumentMetadata->isRepeating($instrument);
                    $primaryJoinField = $meta['principal_date'];
                    // before we claim victory here, first verify that the instrument does in fact have a primary date
                    // if it does not, use its parent's date field
                    if (strlen( $primaryJoinField) === 0) {
                        $parentPrimaryJoinInstrument = $this->instrumentMetadata->instanceSelectLinked($instrument) ;
                        $meta = $this->instrumentMetadata->isRepeating($parentPrimaryJoinInstrument);
                        $parentPrimaryJoinField = $meta['principal_date'];
//                        $module->emDebug('$primaryJoinField is now ' . $parentPrimaryJoinField );
                    }
                    break;
                }
            }
        }
//        $module->emDebug('$json_inp is '.print_r($json_inp,TRUE));
        //  if record_id is missing, add it. Every report needs the record_id
        // look up name of record_id
        $identifier_field = REDCap::getRecordIdField();
        // $record_identifier now contains 'record_id' or whatever the first field is named
        // now look for it in the first pass
        $found_record_identifier = false;

        if (is_array($json_inp->columns)) {

            foreach ($json_inp->columns as $column) {
                $instrument = $column->instrument;
                // if the instrument for this column doesn't exist in final json, create one
                if (!isset($json->forms[$instrument])) {

                    $json->forms[$instrument] = json_decode('{ "fieldsToDisplay" : [] }');
                    $json->forms[$instrument] = json_decode('{ "fieldsToJoin" : [] }');
                    $json->forms[$instrument]->form_name = $column->instrument;

                    $meta = $this->instrumentMetadata->isRepeating($column->instrument);
                    $json->forms[$instrument]->cardinality = $meta['cardinality'];
                    $joinType = $json_inp->cardinality->$instrument->join;
                    // instance select can be of either parent or child form depending on the order of how 
                    // user selects the forms
                    if ($joinType == 'repeating-instance-select') {

                        $json->forms[$instrument]->join_type = 'instance';
                        $json->forms[$instrument]->join_key_field = $meta['foreign_key_field'];
                        $json->forms[$instrument]->foreign_key_field = 'instance';
                        $json->forms[$instrument]->foreign_key_ref = $meta['foreign_key_ref'];

                        // Following is the case where the parent form comes later than the child form
                        if (empty($meta['foreign_key_ref'])) {
                            $childform = $module->hasChild($column->instrument) ;
                            if (empty($childform)) {
                                throw new Exception("No parent/child link defined for " . $column->instrument) ;
                            }
                            $meta = $this->instrumentMetadata->isRepeating($childform);
                            $json->forms[$instrument]->join_key_field = 'instance' ;
                            $json->forms[$instrument]->foreign_key_field = $meta['foreign_key_field'] ;
                            $json->forms[$instrument]->foreign_key_ref = $childform ;
                        }
                        // check if the foreign_key_ref is present in the list of fields to join ; add if missing
                        $f = $json->forms[$instrument];
                        if ( !in_array($f->join_key_field, $f->fieldsToJoin)) {
                            $f->fieldsToJoin[] = $f->join_key_field ;
                        }

                    } else if ($joinType == 'repeating-date-pivot') {

                        $json->forms[$instrument]->join_type = 'date_proximity';
                        $json->forms[$instrument]->lower_bound = $json_inp->cardinality->$instrument->lower_bound;
                        if (! $json->forms[$instrument]->lower_bound) {
                            $json->forms[$instrument]->lower_bound = 999;
                        }
                        $json->forms[$instrument]->upper_bound = $json_inp->cardinality->$instrument->upper_bound;
                        if (! $json->forms[$instrument]->upper_bound) {
                            $json->forms[$instrument]->upper_bound = 999;
                        }
                        $json->forms[$instrument]->primary_date_field = $json_inp->cardinality->$instrument->primary_date;

                        //  repeating form (rhcath)
                        //  pivot - but has no principal date - but parent has principal date (workingdx)
                        //  in this case the primary date will be from parent
                        //  detect this signature by comparing form of the principal date field. If it is not same form,
                        //  then it comes into this section
                        if ($this->getFormForField($json_inp->cardinality->$instrument->primary_date) != $instrument) {
                            // In this case, insert the parent right before the current instrument
                            // I am not sure how to do this in an efficient way in php - i am just creating another 
                            // object with newly inserted form. 
                            $newjson = json_decode('{ "forms" : []}');
                            $newjson->raw_or_label = $json->raw_or_label ;
                            $newjson->record_count = $json->record_count ;
                            $newjson->reportname = $json->reportname ;
                            $newjson->preview = $json->preview ;
                            $newjson->project = $json->project ;

                            foreach ($json->forms as $instrument_t => $form_t) {
                                if ($instrument_t == $instrument) {
                                    // Transfer the current pivot info to the parent/newly created form
                                    $parentInstrument = $this->getFormForField($json_inp->cardinality->$instrument->primary_date) ;
                                    $meta1 = $this->instrumentMetadata->isRepeating($parentInstrument);
                                    $newjson->forms[$parentInstrument]->form_name = $parentInstrument ;
                                    $newjson->forms[$parentInstrument]->cardinality = $meta1['cardinality'];
                                    $newjson->forms[$parentInstrument]->lower_bound = $this->nvl($json_inp->cardinality->$instrument->lower_bound,999);
                                    $newjson->forms[$parentInstrument]->upper_bound =  $this->nvl($json_inp->cardinality->$instrument->upper_bound,999);
                                    $newjson->forms[$parentInstrument]->added_by_filter = true ;
                                    if (isset($meta1['principal_date'])) {
                                        $newjson->forms[$parentInstrument]->fieldsToJoin[] = $meta1['principal_date'];
                                        $newjson->forms[$parentInstrument]->primary_date_field = $meta1['principal_date'];
                                    }
                                    // primaryJoinInstrument is the child and $parentPrimaryJoinField is the parent
                                    $childform = $primaryJoinInstrument ;
                                    $meta1 = $this->instrumentMetadata->isRepeating($childform);
                                    $newjson->forms[$parentInstrument]->join_type = 'date_proximity' ;
                                    $newjson->forms[$parentInstrument]->foreign_key_field = $primaryJoinField; // $meta1['foreign_key_field'] ;
                                    $newjson->forms[$parentInstrument]->foreign_key_ref = $childform ;

                                    // change current instrument from date pivot to instance (child of newly inserted parent)
                                    $form_t->join_type = 'instance' ;
                                    $form_t->join_key_field = $meta['foreign_key_field'] ;
                                    $form_t->foreign_key_ref = $parentInstrument ;
                                    $form_t->foreign_key_field = 'instance' ;

                                    // remove the variables related to the date pivot
                                    unset($form_t->primary_date_field) ;
                                    unset($form_t->lower_bound) ;
                                    unset($form_t->upper_bound) ;
                                    //$form_t->primary_date_field = '' ;
                                    //$form_t->lower_bound = 0 ;
                                    //$form_t->upper_bound = 0 ;

                                }
                                $newjson->forms[$instrument_t] = $form_t ;
                            }
                            $json = $newjson ;
                            //continue ;// PROBLEMATIC - this was dropping columns from some reports
                        }

                        // If the principal join field doesn't exist, we need to add the parent form to the array
                        if (!empty($primaryJoinField)) {
                            // It exists, so it is simple
                            $json->forms[$instrument]->foreign_key_ref = $primaryJoinInstrument;
                            $json->forms[$instrument]->foreign_key_field = $primaryJoinField;

                        } else if (!empty($parentPrimaryJoinField)) {

                            // If it doesn't then look to see if the parent exists in the form
                            if (!array_key_exists($parentPrimaryJoinInstrument, $json->forms)) {
                                // If it doesn't, create a new form element for the parent and insert it 
                                // right before the current element.  Again, the following code is not looking good
                                // but it works.  Find a better way to insert records just before the current element in php
                                $newjson = json_decode('{ "forms" : []}');
                                $newjson->raw_or_label = $json->raw_or_label ;
                                $newjson->record_count = $json->record_count ;
                                $newjson->reportname = $json->reportname ;
                                $newjson->preview = $json->preview ;
                                $newjson->project = $json->project ;
                                foreach ($json->forms as $instrument_t => $form_t) {
                                    if ($instrument_t == $instrument) {
                                        $meta1 = $this->instrumentMetadata->isRepeating($parentPrimaryJoinInstrument);
                                        $newjson->forms[$parentPrimaryJoinInstrument]->form_name = $parentPrimaryJoinInstrument ;
                                        $newjson->forms[$parentPrimaryJoinInstrument]->cardinality = $meta1['cardinality'];
                                        $newjson->forms[$parentPrimaryJoinInstrument]->added_by_filter = true ;
                                        if (isset($meta1['principal_date'])) {
                                            $newjson->forms[$parentPrimaryJoinInstrument]->fieldsToJoin[] = $meta1['principal_date'];
                                        }
                                        // primaryJoinInstrument is the child and $parentPrimaryJoinField is the parent
                                        $childform = $primaryJoinInstrument ;
                                        $meta1 = $this->instrumentMetadata->isRepeating($childform);
                                        $newjson->forms[$parentPrimaryJoinInstrument]->join_type = 'instance' ;
                                        $newjson->forms[$parentPrimaryJoinInstrument]->join_key_field = 'instance' ;
                                        $newjson->forms[$parentPrimaryJoinInstrument]->foreign_key_field = $meta1['foreign_key_field'] ;
                                        $newjson->forms[$parentPrimaryJoinInstrument]->foreign_key_ref = $childform ;
                                        $newjson->forms[$parentPrimaryJoinInstrument]->lower_bound = $this->nvl($json_inp->cardinality->$instrument->lower_bound,998);
                                        $newjson->forms[$parentPrimaryJoinInstrument]->upper_bound =  $this->nvl($json_inp->cardinality->$instrument->upper_bound,998);

                                    }
                                    $newjson->forms[$instrument_t] = $form_t ;
                                }
                                $json = $newjson ;
                            }
                            $json->forms[$instrument]->foreign_key_ref = $parentPrimaryJoinInstrument;
                            $json->forms[$instrument]->foreign_key_field = $parentPrimaryJoinField;
                        } else {
                            // SRINI: This should be an error condition. According to the user guide, first repeating form
                            // should act as the join instrument and it should have the primary date field set.
                            throw new Exception("Missing @PRINCIPAL_DATE field on the primary repeating instrument  " . $primaryJoinInstrument . ".") ;
                            /*
                            // if there's no join field then need to join with this form's parent
                            $meta = $this->instrumentMetadata->isRepeating($primaryJoinInstrument);
                            $json->forms[$instrument]->foreign_key_ref = $meta['foreign_key_ref'];
                            // add the missing parent form
                            $this->add_form_to_spec($meta['foreign_key_ref'], $json);
                            // the parent's principal date is the foreign key field for date proximity join
                            $parentMeta = $this->instrumentMetadata->isRepeating($meta['foreign_key_ref']);
                            $json->forms[$instrument]->foreign_key_field = $parentMeta['principal_date'];
                            */
                        }
                    }
                }
                if (!in_array($column->field, $json->forms[$column->instrument]->fieldsToJoin))
                    $json->forms[$column->instrument]->fieldsToJoin[] = $column->field;

                if (!in_array($column->field, $json->forms[$column->instrument]->fieldsToDisplay))
                    $json->forms[$column->instrument]->fieldsToDisplay[] = $column->field;

                $found_record_identifier = $found_record_identifier || ($column->field === $identifier_field);

                if (!$found_record_identifier) {
                    $json->record_id = $identifier_field;
                }
            }
        }

        $this->add_forms_from_filters($json_inp, $json);
        $json->filters = $json_inp->filters;
//        $module->emDebug('final json ' . print_r($json, true));
        return $json;
    }

    function array_unshift_assoc(&$arr, $key, $val)
    {
        $arr = array_reverse($arr, true);
        $arr[$key] = $val;
        $arr = array_reverse($arr, true);
        return $arr;
    }

    // if the filter includes a min or max, and the form has a parent,
    // then the parent must be included
    // also if there is a filter from a table that is not in columns,
    // then the table for the filter needs to be included as well as it's parent
    function add_forms_from_filters($json_inp, $json) {
        global $module;

        $identifier_field = REDCap::getRecordIdField();
        foreach ($json_inp->filters as $filter) {
            $meta = $this->instrumentMetadata->isRepeating($filter->instrument);
            $is_child = !empty($meta['foreign_key_ref']);
            $has_children = !empty($meta['children']);            
            
            // min and max date filters require a parent form
            // if the parent form is missing, it will be added at end of this function
            if ($filter->operator == 'MAX' || $filter->operator == 'MIN') {
                if ($is_child) {
                    $filter->parent_form = $meta['foreign_key_ref'];
                } else {
                    $filter->parent_form = $this->getFormForField($identifier_field);
                }
            }
            if (!array_key_exists($filter->instrument, $json->forms)) {
                $this->add_form_to_spec($filter->instrument, $json);
                $json->forms[$filter->instrument]->added_by_filter = true ;

                if ($is_child) {
                    $f = $json->forms[$filter->instrument];
                    $f->join_type = 'instance';
                    $f->join_key_field = $meta['foreign_key_field'];
                    $f->foreign_key_ref = $meta['foreign_key_ref'];
                    $f->foreign_key_field = 'instance';
                    $filter->parent_form = $meta['foreign_key_ref'];
                    // check if the foreign_key_ref is one of the forms
                    if ( !in_array($f->join_key_field, $f->fieldsToJoin)) {
                        $f->fieldsToJoin[] = $f->join_key_field ;
                    }
                } else {
                    $filter->parent_form = $this->getFormForField($identifier_field);
                    // need to check if this form has children that are in the query
                    // if so set foreign key references for children
                    if ($has_children) {
                        // if any of the children are in the forms, then set them to join_type instance
                        foreach ($meta['children'] as $child) {
//                            $module->emDebug("Processing children :" . $child . " isset " . isset($json->forms[$child])) ;
                            if (isset($json->forms[$child])) {
                                $childMeta = $this->instrumentMetadata->isRepeating($child);
                                $json->forms[$filter->instrument]->join_type='instance';
                                $json->forms[$filter->instrument]->join_key_field = 'instance';
                                $json->forms[$filter->instrument]->foreign_key_ref = $child;
                                $json->forms[$filter->instrument]->foreign_key_field = $childMeta['foreign_key_field'];
                                //add the foreign key field if it's not already there
                                if (!in_array($childMeta['foreign_key_field'], $json->forms[$filter->instrument]->fieldsToJoin)) {
                                    $json->forms[$filter->instrument]->fieldsToJoin[] = $childMeta['foreign_key_field'];
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    function add_form_to_spec($newForm, $json) {
        $meta = $this->instrumentMetadata->isRepeating($newForm);
        $json->forms[$newForm]->form_name = $newForm;
        $json->forms[$newForm]->cardinality = $meta['cardinality'];
        if (isset($meta['principal_date'])) {
            $json->forms[$newForm]->fieldsToJoin[] = $meta['principal_date'];
        }
    }

    /*
     * use the transformed specification to build and execute the SQL
     e.g.
    [forms] => Array
        (
            [visit] => stdClass Object
                (
                    [fields] => Array
                        (
                            [0] => visit_date
                            [1] => visit_type
                        )

                    [form_name] => visit
                    [cardinality] => repeating
                    [join_type] => repeating-primary
                )

            [meds] => stdClass Object
                (
                    [fields] => Array
                        (
                            [0] => medication
                            [1] => route
                        )

                    [form_name] => meds
                    [cardinality] => repeating
                    [join_type] => instance
                    [foreign_key_ref] => visit
                    [foreign_key_field] => med_visit_instance
                )

            [pft] => stdClass Object
                (
                    [fields] => Array
                        (
                            [0] => pft_test_date
                            [1] => pft_test_result
                        )

                    [form_name] => pft
                    [cardinality] => repeating
                    [join_type] => date_proximity
                    [foreign_key_ref] => visit
                    [foreign_key_field] => visit_date
                    [param1] => 2
                    [param2] => 10
                )

        )

        [preview] => true
        [record_id] => record_id
        [filters] =>
    )
    */
    function runQuery($json)
    {
        global $module;

        $module->emDebug("Input Json :" . print_r($json,TRUE)) ;

        $rowLimit = $module->getProjectSetting('preview-record-limit');
        if (!isset($rowLimit)) {
            $rowLimit = 200;
        }
        if ($json->raw_or_label == "label") {
            //  added length(rd.value) + 2 to remove the "n, " in "n, label" format
            $valSel = "coalesce(SUBSTRING_INDEX(substring(element_enum, instr(element_enum, concat(rd.value, ',')) + length(rd.value) + 2), '\\\\n',1), rd.value)";
        } else {
            $valSel = "rd.value";
        }
        $project_id = $this->Proj->project_id;
//        $select = "";
//        $from = "";

        // record count feature - as explained by Susan
        if ($json->record_count === 'true') {

            $sql = "select count(distinct rdm.record) as row_count from redcap_data rdm where rdm.project_id = " . $project_id . " AND ";
            if (is_array($json->filters) || is_object($json->filters)) {
                foreach ($json->filters as $filterIdx => $filter) {
                    // for now ignore max and min in doing counts.  will need to think about how to do this in combo
                    // with other parameters
                    if ($filter->operator != 'MAX' && $filter->operator != 'MIN') {

                        $filterstr = $this->filter_string($filter);

                        $filter_val_sel = "(case when rd.field_name = '" . $filter->field . "' and (rm.element_type = 'calc' or coalesce(rm.element_enum, '') = '') then rd.value " .
                                          " when rd.field_name = '" . $filter->field . "' then $valSel end) " ;

                        $filterstr = str_replace($filter->instrument . '.' . $filter->field, $filter_val_sel, $filterstr);

                        $sql = $sql . " rdm.record in (select record from redcap_data rd, redcap_metadata rm where rd.project_id = rm.project_id and rd.field_name = rm.field_name and " .
                            "     rd.project_id = " . $project_id . " and rd.field_name = '" . $filter->field . " ' and " .
                            $filterstr . ") " . " " . $filter->boolean . " ";
                    }
                }
            }

            if (substr($sql, -4) == "AND ")
                $sql = substr($sql, 0, strlen($sql) - 4);

            if (substr($sql, -3) == "OR ")
                $sql = substr($sql, 0, strlen($sql) - 3);

//            $module->emDebug("SQL for COUNT : " . $sql);

            $result1 = db_query($sql);

            $row = db_fetch_assoc($result1);

            $result["count"] = $row["row_count"];
            return $result;
        }

        $select_fields = array();  // Fields which will be returned to the caller
//        $all_fields = array();  // fields including the filters in the selected instruments
//
        $recordFieldIncluded = !isset($json->record_id);
////        $module->emDebug('is record_id included? ' . $recordFieldIncluded . ' ' . $json->record_id);
//        $primaryFormName = "";
//
//        // Keep the order of the fields as specified by the user
        foreach ($json->forms as $form) {
            // Doing coalesce so nulls will be displayed as '' in output reports
            foreach ($form->fieldsToDisplay as $field) {
                $select_fields[] = $field;
//                $select = $select . (($select == "") ? " " : ", ") . "COALESCE(`" . $field . "`, '') " . "`" . $field . "`";
//                $all_fields[] = $field;
            }
        }
//
//        foreach ($json->forms as $formIdx => $form) {
//            $form->form_name = trim($form->form_name) ;
//            $form->form_name_alias = $form->form_name . "_a" ;
//            if (isset($form->foreign_key_ref)) {
//                $form->foreign_key_ref_alias = trim($form->foreign_key_ref) . "_a" ;
//                // SDH-132 - add the foreign key field to the fieldstojoin array - only if it is not 'instance' as this is added by default
//                if ($form->foreign_key_field != 'instance' && !in_array($form->foreign_key_field, $json->forms[$form->foreign_key_ref]->fieldsToJoin))
//                    $json->forms[$form->foreign_key_ref]->fieldsToJoin[] = $form->foreign_key_field ;
//                //$form->foreign_key_ref = trim($form->foreign_key_ref) . "_a" ;
//            }
//            // SDH-132 - add the join key field to the fieldstojoin array - only if it is not 'instance' as this is added by default
//            if (isset($form->join_key_field)) {
//                if ($form->join_key_field != 'instance' && !in_array($form->join_key_field, $form->fieldsToJoin))
//                    $form->fieldsToJoin[] = $form->join_key_field ;
//            }
//
//        }
//        if (isset($json->filters)) {
//            foreach ($json->filters as $filter) {
//                $filter->instrument = $filter->instrument . "_a" ;
//            }
//        }
////        $module->emDebug("final json after aliasing :" . print_r($json, TRUE)) ;
//
//        foreach ($json->forms as $formIdx => $form) {
//
//            // To identify the key instrument - first instrument is considered primary and all other instruments will
//            // be joined as left outer joins  - sorting is done in earlier loop
//
//            $primaryForm = ($from == "");
//            if ($primaryForm) {
//                $primaryFormName = $form->form_name_alias;
//            }
//
//            // mapping null instance to '1'
//            $formSql = (($form->cardinality == 'singleton') ? " ( select /*line599a*/ rd.record " : " ( select /*line599b*/ rd.record, COALESCE(rd.instance, '1') instance ");
//
//            // Converting redcap_data into a view format for each selected fields
//            foreach ($form->fieldsToJoin as $field) {
//                // changed from max to group_contact to handle checkbox values - SDM-109
//                // handling calc type - SDM-119
//                $formSql = $formSql . ", group_concat(distinct case when rd.field_name = '" . $field . "' and (rm.element_type = 'calc' or coalesce(rm.element_enum, '') = '') then rd.value " .
//                                                    " when rd.field_name = '" . $field . "' then $valSel end separator '\\n') `" . $field . "` ";
//            }
//
//            // Add to the view, if it is included in the filters also
//            if (is_array($json->filters) || is_object($json->filters)) {
//                foreach ($json->filters as $filter) {
//                    if ($filter->instrument == $form->form_name_alias && !in_array($filter->field, $select_fields)) {
//                        $all_fields[] = $filter->field;
//                        $formSql = $formSql . ", group_concat(distinct case when rd.field_name = '" . $filter->field . "' and (rm.element_type = 'calc' or coalesce(rm.element_enum, '') = '') then rd.value " .
//                                                                     "  when rd.field_name = '" . $filter->field . "' then $valSel end separator '\\n') `" . $filter->field . "` ";
//
//                    }
//                }
//            }
//
//            // date proximity is a very special case - this is the first try - not sure about the performance yet
//            // Test with realistic data set and change if needed.
//            if ($form->join_type == "date_proximity") {
//
//                $upperBoundSet = (isset($form->upper_bound) && strlen(trim($form->upper_bound)) > 0);
//                $lowerBoundSet = (isset($form->lower_bound) && strlen(trim($form->lower_bound)) > 0);
//
//                $formSql = "Select distinct " . $form->form_name . "_int.*, " . $form->form_name . "_dproxy." . $form->foreign_key_ref . "_instance " .
//                    "From " .
//                    $formSql . " FROM redcap_data rd, redcap_metadata rm " .
//                    "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
//                    "GROUP BY rd.record, rd.instance ) " . $form->form_name . "_int, " .
//                    " (select rd.record, COALESCE (rd.`instance`, 1) as " . $form->foreign_key_ref . "_instance , rd.value as " . $form->foreign_key_field . " , " .
//                    "    (select COALESCE (rd2.`instance` , 1) from redcap_data rd2, redcap_metadata rm2 " .
//                    "      where rd2.project_id = rm2.project_id and rm2.field_name = rd2.field_name and rd2.project_id = " . $project_id . " " .
//                    "           and rd2.field_name = '" . $form->primary_date_field . "' and rd2.record  = rd.record " .
//                    ($lowerBoundSet ? ("   and datediff(rd.value, rd2.value) <= " . $form->lower_bound . " ") : " ") .
//                    ($upperBoundSet ? ("   and datediff(rd2.value, rd.value) <= " . $form->upper_bound . " ") : " ") .
//                    "      order by abs(datediff(rd2.value, rd.value)) asc limit 1 " .
//                    "    ) as " . $form->form_name . "_instance " .
//                    "  from redcap_data rd, redcap_metadata rm " .
//                    "  where rd.project_id = rm.project_id and rd.project_id  = " .$project_id . " " .
//                    "     and rm.field_name = rd.field_name	and rd.field_name = '" . $form->foreign_key_field . "' and rm.form_name = '" . $form->foreign_key_ref . "' " .
//                    "         ) " . $form->form_name . "_dproxy " .
//                    "where " . $form->form_name . "_int.instance = " . $form->form_name . "_dproxy." . $form->form_name . "_instance " .
//                    " and " . $form->form_name . "_int.record = " . $form->form_name . "_dproxy.record ) " . $form->form_name_alias ; // . " ON " .
//                    //" ( " . $form->form_name . ".record = " . $form->foreign_key_ref . ".record " .
//                    //"    and " . $form->form_name . "." . $form->foreign_key_ref . "_instance = " . $form->foreign_key_ref . ".instance ) " ;
//
//                //$formSql = $formSql . ") " . $form->form_name_alias ;
//
//                if ($primaryForm) {
//                    $from = "FROM " . $formSql;
//                } else {
//                    $from = $from . " left outer join ( " . $formSql . " ON ( " . $form->form_name_alias . ".record = " . $primaryFormName . ".record  " .
//                        "and " . $form->form_name_alias . "." . $form->foreign_key_ref . "_instance = " . $form->foreign_key_ref_alias . ".instance )";
//                }
//
//            } else {
//
//                // Singletons - group by the record only
//                if ($form->cardinality == "singleton") {
//                    $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " .
//                        "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
//                        "GROUP BY rd.record";
//                } else {   // for repeating forms group by record and instance
//                    $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " .
//                        "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
//                        "GROUP BY rd.record, rd.instance";
//                }
//
//                $formSql = $formSql . ") " . $form->form_name_alias ;
//
//                if ($primaryForm) {
//                    $from = "FROM " . $formSql;
//                } else {
//                    $from = $from . " left outer join " . $formSql . " ON ( " . $form->form_name_alias . ".record = " . $primaryFormName . ".record  ";
//
//                    // If it is instance join type, join with "instance" column of the parent
//                    if (isset($form->join_type)) {
//                        if ($form->join_type == "instance") {
//                            //$form->join_condition = $form->foreign_key_field . " = " . $form->foreign_key_ref . ".instance";
//                            $form->join_condition = $form->form_name_alias . "." . $form->join_key_field . " = " . $form->foreign_key_ref_alias . "." . $form->foreign_key_field;
//                        } elseif ($form->join_type == "lookup") {  // Not implemented yet. To support joining any columns
//                            $form->join_condition = $form->form_name_alias . "." . $form->join_key_field . " = " . $form->foreign_key_ref_alias . "." . $form->foreign_key_field;
//                        }
//                    }
//
//                    if (isset($form->join_condition)) {
//                        $from = $from . " and " . $form->join_condition;
//                    }
//
//                    $from = $from . " ) ";
//                }
//            }
//        }
//
        // If record_id is not chosen add it to the SQL
//        if ($recordFieldIncluded)
//            $sql = "Select " . $select . " " . $from;
//        else {
//            $sql = "Select " . $primaryFormName . ".record as " . $json->record_id . ", " . $select . " " . $from;
        if (! $recordFieldIncluded) {
            array_unshift($select_fields, $json->record_id);   // add record_id as the first field in the fields array
        }
//// It was this pattern, applying the filters at the very end globally, that triggered the re-write.
//        if (isset($json->filters)) {
//            $filtersql = $this->processFilters($json->filters, $all_fields, $valSel, $primaryFormName);
//            if (strlen($filtersql) > 0) {
//                $sql = $sql . " where " . $filtersql;
//            }
//        }

        $sql = $this->getSqlMultiPass($json, $this->Proj->project_id);

        if ("true" == $json->preview && strlen(trim($sql)) > 0) {
            $sql = $sql . " LIMIT " . $rowLimit;
        }

        $module->emDebug($sql);

        if (strlen(trim($sql)) > 0) {
            $rptdata = db_query($sql);

            $result["status"] = 1; // when status = 0 the client will display the error message
            if (strlen(db_error()) > 0) {
                $dberr = db_error();
                error_log($dberr);
                $module->emlog($dberr);
                $result["status"] = 0;
                $result["message"] = $dberr;
            } else {
                $result["t1"]  = $this->package($json->preview, $select_fields, $rptdata);

//                $result["t2"] = $this->package($json->preview, $select_fields, $rptdata);

            }
        } else {
            $result["status"] = 0;
            $result["message"] = "No data requested. You must specify at least one column";
        }

        return $result;
    }

    function package($preview, $select_fields, $rptdata) {
        $data = [];
        if ("false" == $preview) {
            // when exporting .csv, the return csv is in $data
            $data[] = $this->pivotCbHdr($select_fields);  // $headers;
        }
        while ($row = db_fetch_assoc($rptdata)) {
            $cells = [];
            for ($k = 0; $k < count($select_fields); $k++) {
                $cells = array_merge($cells, $this->pivotCbCell($select_fields[$k], $row[$select_fields[$k]]));
            }
            $data[] = $cells;
            //$module->emDebug('merged: ' . print_r($data, TRUE));
        }
        // when previewing, the return data is in $result, which includes $data
        $result["headers"] = $this->pivotCbHdr($select_fields);
        $result["data"] = $data;
        return $result;
    }

    function endsWith($string, $endString)
    {
        $len = strlen($endString);
        if ($len == 0) {
            return true;
        }
        return (substr($string, -$len) === $endString);
    }

    function filter_string($filter)
    {
        $col =  $filter->field;
        $val = db_escape($filter->param);
        $dt = "string";

        if ($this->endsWith($filter->validation, "_dmy")) {
            $col = "str_to_date(" . $col . ", '%Y-%m-%d')";
            $val = "str_to_date('" . $val . "', '%d-%m-%Y')";
            $dt = "date";
        } elseif ($this->endsWith($filter->validation, "_mdy")) {
            $col = "str_to_date(" . $col . ", '%Y-%m-%d')";
            $val = "str_to_date('" . $val . "', '%m-%d-%Y')";
            $dt = "date";
        } elseif ($this->endsWith($filter->validation, "_ymd")) {
            $col = "str_to_date(" . $col . ", '%Y-%m-%d')";
            $val = "str_to_date('" . $val . "', '%Y-%m-%d')";
            $dt = "date";
        }

        if (($filter->validation == "integer" || $filter->validation == "number"))
            $dt = "number";

        if ($filter->operator == "E")
            $filterstr = ($dt == "string") ? ($col . " = '" . $val . "'") : ($col . " = " . $val);
        elseif ($filter->operator == "NE")
            $filterstr = ($dt == "string") ? ($col . " <> '" . $val . "'") : ($col . " <> " . $val);
        elseif ($filter->operator == "CONTAINS")
            $filterstr = $col . " like '%" . $val . "%'";
        elseif ($filter->operator == "NOT_CONTAIN")
            $filterstr = $col . " not like '%" . $val . "%'";
        elseif ($filter->operator == "EXISTS")
            $filterstr = $col . " is not null";
        elseif ($filter->operator == "NOT_EXIST")
            $filterstr = $col . " is null";
        elseif ($filter->operator == "STARTS_WITH")
            $filterstr = $col . " like '" . $val . "%'";
        elseif ($filter->operator == "ENDS_WITH")
            $filterstr = $col . " like '%" . $val . "'";
        elseif ($filter->operator == "LT")
            $filterstr = ($dt == "string") ? ($col . " < '" . $val . "'") : ($col . " < " . $val);
        elseif ($filter->operator == "LTE")
            $filterstr = ($dt == "string") ? ($col . " <= '" . $val . "'") : ($col . " <= " . $val);
        elseif ($filter->operator == "GT")
            $filterstr = ($dt == "string") ? ($col . " > '" . $val . "'") : ($col . " > " . $val);
        elseif ($filter->operator == "GTE")
            $filterstr = ($dt == "string") ? ($col . " >= '" . $val . "'") : ($col . " >= " . $val);
        elseif ($filter->operator == "CHECKED")
            $filterstr = $col . " like '%" . $val . "%'";
        elseif ($filter->operator == "UNCHECKED")
            $filterstr = $col . " not like '%" . $val . "%'";
        elseif ($filter->operator == "MAX" || $filter->operator == "MIN")
            // make sure that person is one of the tables in the join
            $filterstr = $col . " = (select ".$filter->operator."(rdx.value) from redcap_data rdx, redcap_metadata rmx
          where rdx.project_id  = rmx.project_id and rmx.field_name  = rdx.field_name and rdx.project_id  = "
                .$this->Proj->project_id." and rdx.field_name = '".$filter->field."' and rdx.record=".
                $filter->parent_form.".record) ";

        return $filterstr;
    }
    // Just the raw format values. v1 - needs iteration
    /*  Possible Validation types - Right now, code handles only dates, numbers as special case
    "date_dmy"
    "date_mdy"
    "date_ymd"
    "datetime_dmy"
    "datetime_mdy"
    "datetime_ymd"
    "datetime_seconds_dmy" (D-M-Y H:M:S)
    "datetime_seconds_mdy" (M-D-Y H:M:S)
    "datetime_seconds_ymd" (Y-M-D H:M:S)
    "email"
    "integer"
    "number"
    "phone" Phone (North America)
    "time" (HH:MM)
    "zipcode" Zipcode (U.S.)
    */
//    function processFilters($filters, $all_fields, $valSel, $primaryFormName)
//    {
//
////        global $module;
//
////        $module->emDebug('all fields :' . print_r($all_fields, TRUE));
//
//        $filtersql = "";
//
//        foreach ($filters as $filter) {
//
//            $filterstr = $this->filter_string($filter);
//
//            // SRINI - 11/23/2020: Code shouldn't go into the following based on new approach of adding
//            // forms to the spec.  Keeping this code for reference. will remove once tested and confirmed.
//            if (!in_array($filter->field, $all_fields)) {
//
//                $filterstr = str_replace($filter->instrument . "." . $filter->field, $valSel, $filterstr);
//
//                $filterstr = " exists (select 1 from redcap_data rd, redcap_metadata rm " .
//                    "    where rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id  = " . $this->Proj->project_id . " and " .
//                    "         rd.field_name = '" . $filter->field . "' and " . $filterstr .
//                    "   and rd.record  = " . $primaryFormName . ".record ) ";
//
//                $filtersql = $filtersql . $filterstr . " " . $filter->boolean . " ";
//            } else {
//                $filtersql = $filtersql . $filterstr . " " . $filter->boolean . " ";
//            }
//        }
//
//        if (substr($filtersql, -4) == "AND " || substr($filtersql, -4) == "OR ")
//            $filtersql = substr($filtersql, 0, strlen($filtersql) - 4);
//
//        return $filtersql;
//
//    }

    private function isCheckbox($field)
    {
        return $this->Proj->metadata[$field]['element_type'] === 'checkbox';
    }

    private function cbLov($field)
    {
        return $this->Proj->metadata[$field]['element_enum'];
    }

    private function pivotCbHdr($headers)
    {
        $newHeaders = [];
        foreach ($headers as $field) {
            if ($this->isCheckbox($field)) {
                $lovstr = $this->cbLov($field);
                $lov = explode("\\n ", $lovstr);
                for ($i = 1; $i < count($lov) + 1; ++$i) {
                    $newHeaders[] = $field . '___' . $i;
                }
            } else {
                $newHeaders[] = $field;
            }
        }

        return $newHeaders;
    }

    private function pivotCbCell($field, $cellValue)
    {
        $newCells = [];
        $loSetValues = explode("\n", $cellValue);
        if ($this->isCheckbox($field)) {
            $lovstr = $this->cbLov($field);
            $lov = explode("\\n ", $lovstr);
            $j = 0;// this will keep track of where we are in the list of selected values
            for ($i = 0; $i < count($lov); ++$i) {
                // consider each possible value from the data dictionary in turn
                if (strpos($lov[$i], $loSetValues[$j]) === FALSE) {
                    $newCells[] = '';
                } else {
                    $newCells[] = $loSetValues[$j];
                    $j++;
                }
            }
        } else {
            $newCells[] = $cellValue;
        }

        return $newCells;
    }


    /**
     * @return bool
     */
    public function isUseTempFile()
    {
        return false ;   // disable until the issues with stale cache files masking current data have been addressed
       // return $this->useTempFile;
    }

    /**
     * @param bool $useTempFile
     */
    public function setUseTempFile($useTempFile)
    {
        $this->useTempFile = $useTempFile;
    }

    /**
     * @return mixed
     */
    public function getTempFileConfig()
    {
        return $this->tempFileConfig;
    }

    /**
     * @param mixed $tempFileConfig
     */
    public function setTempFileConfig($tempFileConfig)
    {
        $this->tempFileConfig = $tempFileConfig;
    }


    public function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * @return mixed
     */
    public function getDaysToCache()
    {
        return $this->daysToCache;
    }

    /**
     * @param mixed $daysToCache
     */
    public function setDaysToCache($daysToCache)
    {
        $this->daysToCache = $daysToCache;
    }

    /**
     * @return mixed
     */
    public function getTempFileDate()
    {
        return $this->tempFileDate;
    }

    /**
     * @param mixed $tempFileDate
     */
    public function setTempFileDate($tempFileDate)
    {
        $this->tempFileDate = $tempFileDate;
    }

    /**
     * @return mixed
     */
    public function getTempFilePath()
    {
        return $this->tempFilePath;
    }

    /**
     * @param mixed $tempFilePath
     */
    public function setTempFilePath($tempFilePath)
    {
        $this->tempFilePath = $tempFilePath;
    }

    /**
     * @return mixed
     */
    public function getTempFileJSON()
    {
        return $this->tempFileJSON;
    }

    /**
     * @param mixed $tempFileJSON
     */
    public function setTempFileJSON($tempFileJSON)
    {
        $this->tempFileJSON = $tempFileJSON;
    }

    public function getFormForField($fieldName) {
        return $this->Proj->metadata[$fieldName]['form_name'];
    }

//    public function getInlineTablePivot($fieldName, $formName, $pid, $rd, $rm) {
//        $sql= "select /*line1038*/ $rd.record, COALESCE($rd.instance, '1') ".$formName."_instance,
//        group_concat(distinct case when $rd.field_name = '$fieldName' and (rm.element_type = 'calc' or coalesce(rm.element_enum, '') = '') then $rd.value
//                                   when $rd.field_name = '$fieldName' then coalesce(SUBSTRING_INDEX(substring(element_enum, instr(element_enum, concat($rd.value, ',')) + length($rd.value) + 2),'\\n', 1), $rd.value)
//                              end
//                              separator '\n') `$fieldName`
//      FROM redcap_data $rd,
//           redcap_metadata $rm
//      WHERE $rd.project_id = $rm.project_id
//        and $rm.field_name = $rd.field_name
//        and $rd.project_id = $pid
//        and $rm.form_name = '$formName'
//      ";
//        if ($simple) {
//            return $sql . "GROUP BY $rd.record";
//        } else {
//            return $sql . "and rd2.field_name = '$fieldName'
//                                          and rd2.record = rd.record
//                                          and datediff(rd.value, rd2.value) <= 1
//                                          and datediff(rd2.value, rd.value) <= 40
//                                        order by abs(datediff(rd2.value, rd.value)) asc
//                                        limit 1";
//        }
//    }

    function handleFilters($filters, $formName, $prefix)
    {
//        global $module;
//
//        $module->emDebug('filters :' . print_r($filters, TRUE));

        $filtersql = " $prefix ";
        if (is_array($filters) || is_object($filters)) {
            foreach ($filters as $filter) {

                if ($filter->instrument == $formName) {
                    $filtersql = $filtersql . $this->filter_string($filter) . " " . $filter->boolean . " ";
                }
            }
        }

        if (strlen($filtersql) > 7) {
            if (substr($filtersql, -4) == "AND " || substr($filtersql, -4) == " OR ") {
                $filtersql = substr($filtersql, 0, strlen($filtersql) - 4);
            }
        } else {
            $filtersql = "";
        }


        return $filtersql;

    }

    function getFields($instrument, $fields) {
        $fieldstr = "";
        $cnt = 0;
        if (is_array($fields) || is_object($fields)) {
            foreach ($fields as $field) {
                if ($cnt > 0) {
                    $fieldstr .= ", ";
                }
                $fieldstr .= "COALESCE ($instrument.$field,'') `$field`";
                $cnt++;
            }
        }

        return $fieldstr;
    }

    function getSqlMultiPass($json, $pid) {
        global $module;
        $recordId =  REDCap::getRecordIdField();
        $finalSql = "";
        $priorTable = "";

        $filters = $json->filters;
        // in the first pass, process the instance joins, packaging them as a single inline table
        // then in the second pass, first process singletons, then process any date proximity joins
        // taking care as you do so that the user-specified column ordering is preserved
        // doing it this way allows you to specify filters on the instance tables without losing rows of data
        // cache the results in a data structure
        $tablePivots = array();

        foreach ($json->forms as $formName => $spec) {
            if ($spec->join_type != 'instance') {
                continue;
            }
            $children[$spec->foreign_key_ref][] = $spec->form_name;
        }
        // ok, $children, which may be empty, now has parent-child relationships for all INSTANCE-SELECT linked tables
        // so as the second half of the first pass, process the data structure and cache the SQL for these linked tables
//        $module->emDebug('$children :' . print_r($children, TRUE));
        foreach ($children as $formName => $instances) {
            // start with the parent
            $spec = $json->forms[$formName];
            $selectClause = "$formName.record_id, ".$formName."_instance, ";
            $selectClause .= $this->getFields($formName, $spec->fieldsToDisplay);
            $fieldNames = $this->augment($spec->form_name, $spec->fieldsToJoin, $filters);
            $inlineTable = $this->getInlineTableSql($fieldNames, $spec->form_name, $pid, $spec, $filters);
            foreach ($instances as $childForm) {
                $spec = $json->forms[$childForm];
                if (count($spec->fieldsToDisplay) > 0) {
                    $selectClause .= ", /*1147 */" . $this->getFields($childForm, $spec->fieldsToDisplay);
                }
                $fieldNames = $this->augment($spec->form_name, $spec->fieldsToJoin, $filters);
                $inlineTable .= $this->getJoin($childForm, $filters);
                $inlineTable .= $this->getInlineTableSql($fieldNames, $spec->form_name, $pid, $spec, $filters);
                $inlineTable .= " ON $formName.$recordId=$spec->form_name.$recordId AND /*1*/ $spec->join_key_field = " . $spec->foreign_key_ref . "_instance";
            }
            $inlineTable = "(select  $selectClause  FROM ( $inlineTable ) ) $formName" ;
            $tablePivots[$formName] = $inlineTable;
        }
        $module->emDebug('$inlineTable :' . print_r($tablePivots, TRUE));

        $selectClause = $this->getSelectClause($json); // this counts as pass #2
        $cntTableJoins = 0;
        // the third pass picks up all singleton forms
        foreach ($json->forms as $formName => $spec) {
            if ($spec->join_type != 'singleton') {
                continue;
            }

            $finalSql .= $this->getInterimSql( $json, $pid, $cntTableJoins, $formName, $spec, $filters, $tablePivots, $priorTable);
            $cntTableJoins ++;
            $priorTable = $formName;

        }
        // ok, now in this pass (#4), skip over forms with join_type instance
        // and also skip forms that already have their SQL generated
        // and re-create a complete select list for the outer layer

        $cntTableJoins = 0;
        foreach ($json->forms as $formName => $spec) {

//            $module->emDebug('$selectClause ' . print_r($selectClause,TRUE));
            // carry on generating the remaining table pivots and joins
            if ($spec->join_type == 'singleton' || $spec->join_type == 'instance') {
                continue;
            }

            $finalSql .= $this->getInterimSql( $json, $pid, $cntTableJoins, $formName, $spec, $filters, $tablePivots, $priorTable);
            // date_proximity has the join hard-coded; see getDateProximityTableJoin()
            $cntTableJoins ++;
            $priorTable = $formName;
        }
        $finalSql = "select distinct " . $selectClause . " FROM " . $finalSql;
        return $finalSql;
    }

    function getInterimSql( $json, $pid, $cntTableJoins, $formName, $spec, $filters, $tablePivots, $priorTable) {
        global $module;
        $recordId = REDCap::getRecordIdField();
        $innerTableSql = "";
        $joinClause = "";
        if ($cntTableJoins > 0) {
            if ($spec->join_type == 'date_proximity') {
                $finalSql = " LEFT OUTER JOIN ";
            } else {
                $finalSql = $this->getJoin($formName, $filters);
            }
        }
        if (strlen($tablePivots[$formName]) > 0) {
            $innerTableSql = $tablePivots[$formName];
        }
        // singleton or date_proximity
        $spec = $json->forms[$formName];
        $fieldNames = $this->augment($spec->form_name, $spec->fieldsToJoin, $filters);
        if ($spec->join_type == 'date_proximity') {
            if (strlen($innerTableSql) == 0) {
                $innerTableSql = $this->getInnerTableSql($spec->form_name, $this->getFieldList($fieldNames), $pid);
            } else {
                // Remove the table alias from the end of this string
                // when $tablePivots $innerTableSql used in this context
                $innerTableSql = $this->str_lreplace($formName , '', $innerTableSql);
            }
            $pieces = $this->getDateProximityTableJoin($spec->form_name, $innerTableSql, $spec, $pid, $filters);
            $finalSql .= $pieces['tableSql'];
            $joinClause .= $pieces['joinClause'];
        } else {
//            $module->emDebug('$innerTableSql :' . print_r($innerTableSql, TRUE));
            if (strlen($innerTableSql) == 0) {
                $innerTableSql = $this->getInlineTableSql($fieldNames, $spec->form_name, $pid, $spec, $filters);
            }
            $finalSql .= $innerTableSql;
        }

        if ($cntTableJoins > 0) {
            if ($spec->join_type != 'date_proximity') {
                $finalSql .= " ON $formName.$recordId=$priorTable.$recordId ";
            } else {
                $finalSql .= " " . $joinClause;
            }
        }
        return $finalSql;
    }
    function getSelectClause($json) {
        $selectClause = "";
        $cntSelectClauses = 0;
        foreach ($json->forms as $formName => $spec) {
            $fieldsToDisplay = $spec->fieldsToDisplay;
//            $module->emDebug('$spec '. $formName. ' ' . print_r($spec,TRUE));
//            $module->emDebug('$fieldsToDisplay ' . print_r($fieldsToDisplay,TRUE));
            if ($cntSelectClauses++ == 0) {
                if (strlen($json->record_id) > 0) {
                    // hard-code a reference to the record_id field
                    // every report should be prefixed with the record_id regardless of whether or not they asked for it
                    array_unshift($fieldsToDisplay, $json->record_id);
                }
//                $module->emDebug('$fieldsToDisplay ' . print_r($fieldsToDisplay,TRUE));
            } else if (count($fieldsToDisplay) > 0) {
                $selectClause .= ", /*1175*/";
            }

            if ($spec->join_type == 'instance') {
                $selectClause .= $this->getFields($spec->foreign_key_ref, $fieldsToDisplay);
                // now that we've picked up their fields to display we're done,
                // as the from clause has already been incorporated into the SQL cached in the earlier pass
                continue;
            } else {
                $selectClause .= $this->getFields($formName, $fieldsToDisplay);
            }
        }
        return $selectClause;
    }
    function str_lreplace($search, $replace, $subject)
    {
        $pos = strrpos($subject, $search);

        if($pos !== false)
        {
            $subject = substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }
    function getJoin($formName, $filters) {
        global $module;
        // if there is a matching supplied filter, use inner join, because that's the semantics of a filter
        // otherwise use outer join, so you don't lose rows from the parent if the child table is missing data
        $module->emDebug("getJoin: " . $formName);
        foreach ($filters as $spec) {
            $module->emDebug("OK " . print_r($spec,TRUE));
            if ($spec->instrument == $formName) {
                return " INNER JOIN ";
            }
        }
        return " LEFT OUTER JOIN ";
    }

/*    function getSql($json, $pid) {
        $recordId =  REDCap::getRecordIdField();
        $finalSql = "";
        $joinClause = "";
        $selectClause = "";
        $cnt = 0;
        $filters = $json->filters;
        foreach ($json->forms as $formName => $spec) {
            $fieldNames = $this->augment($formName, $spec->fieldsToJoin, $filters);
            $fieldsToDisplay = $spec->fieldsToDisplay;
            if ($cnt == 0) {
                if (strlen($json->record_id) > 0) {
                    // hard-code a reference to the record_id field
                    // every report should be prefixed with the record_id regardless of whether or not they asked for it
                    array_unshift($fieldsToDisplay, $json->record_id);
                }
            } else if (count($fieldsToDisplay) > 0) {
                $selectClause .= ",";
            }

            $selectClause .= $this->getFields($formName, $fieldsToDisplay);
            if ($cnt > 0) {
                if ($spec->join_type == 'date_proximity') {
                    $finalSql .= " LEFT OUTER JOIN ";
                } else {
                    $finalSql .= " INNER JOIN ";
                }
            }

            $finalSql .= $this->getInlineTableSql($fieldNames, $formName, $pid, $spec, $filters);
            if ($cnt > 0) {
                if ($spec->join_type != 'date_proximity') {

                    $finalSql .= " ON $formName.$recordId=$priorTable.$recordId ";
                }
                if ($spec->join_type == 'instance') {
                    $finalSql .= "  AND $formName.$spec->join_key_field = " . $spec->foreign_key_ref . "_instance";
                }
            }
            // date_proximity has the join hard-coded; see getDateProximityTableJoin()
            $cnt ++;
            $priorTable = $formName;
        }
        $finalSql = "select distinct " . $selectClause . " FROM " . $finalSql . $joinClause ;
        return $finalSql;
    } */

    function augment($formName, $fieldsToJoin, $filters) {
//        global $module;
        foreach ($filters as $spec) {
            if ($spec->instrument == $formName && !in_array($spec->field, $fieldsToJoin)) {
                $fieldsToJoin[] = $spec->field;
            }
        }
//        $module->emDebug('filters :' . print_r($filters, TRUE));
//        $module->emDebug('fieldsToJoin :' . print_r($fieldsToJoin, TRUE));
        return $fieldsToJoin;
    }

    function getFieldList($fieldNames) {
        $fieldList = "";
        $recordId =  REDCap::getRecordIdField();

        foreach ($fieldNames as $index => $fieldName) {
            if ($fieldName == $recordId) {
                //  the one special case is record_id ; this is hard-coded in getSql, so it needs to be suppressed here
                continue;
            }
            if ($nItems > 0) {
                $fieldList .= ", /*1306*/\n";
            }
            $nItems++;
            $fieldList .= "group_concat(distinct case when rd.field_name = '$fieldName' and (rm.element_type = 'calc' or coalesce(rm.element_enum, '') = '') then rd.value
                                   when rd.field_name = '$fieldName' then coalesce(SUBSTRING_INDEX(substring(element_enum, instr(element_enum, concat(rd.value, ',')) + length(rd.value) + 2),'\\\\n', 1), rd.value) 
                              end
                              separator '\\n') `$fieldName`";
        }
        return $fieldList;
    }

    function getInlineTableSql($fieldNames, $formName, $pid, $spec, $filters)
    {
        //  build up the list of field names for the current instrument
        $fieldList = $this->getFieldList($fieldNames);
//        global $module;

//        $module->emDebug('CARDINALITY :' . print_r($spec, TRUE));

        if ($spec->cardinality == "singleton") {
            $instanceSelect = "";
            $grouper = " GROUP BY rd.record";
        } else {
            $instanceSelect = "COALESCE(rd.`instance`, 1) as ".$formName."_instance,";
            $grouper = " GROUP BY rd.record, ".$formName."_instance";
        }

        return $this->getTableJoinClause( REDCap::getRecordIdField(), $fieldList, $pid, $formName, $filters, $grouper, $instanceSelect);
    }

    // use case: 1. rhcath 2. visit 3. workingdx
    // i.e. date pivot on a table that is the parent in a parent-child relationship
    function getInnerTableSql($formName, $fieldList,  $pid) {
        $recordId =  REDCap::getRecordIdField();
        return "select  rd.record as $recordId,
                        COALESCE(rd.instance, '1') ".$formName."_instance,
                        $fieldList
                                FROM redcap_data rd,
                                     redcap_metadata rm
                                WHERE rd.project_id = rm.project_id
                                  and rm.field_name = rd.field_name
                                  and rd.project_id = $pid
                                  and rm.form_name = '$formName'
                                GROUP BY rd.record, rd.instance";
    }

    function getDateProximityTableJoin($formName, $innerTableSql, $spec, $pid, $filters) {
        $recordId =  REDCap::getRecordIdField();
        $filter = $this->handleFilters($filters, $formName, 'AND');
        $tableSql =  "(Select distinct ".$formName."_int.*, ".$formName."_dproxy.".$spec->foreign_key_ref."_instance
                          From ( $innerTableSql ) ".$formName."_int,
                               (select rd.record as $recordId,
                                       COALESCE(rd.`instance`, 1) as ".$spec->foreign_key_ref."_instance,
                                       rd.value                   as ".$spec->foreign_key_field.",
                                       (select COALESCE(rd2.`instance`, 1)
                                        from redcap_data rd2,
                                             redcap_metadata rm2
                                        where rd2.project_id = rm2.project_id
                                          and rm2.field_name = rd2.field_name
                                          and rd2.project_id = $pid
                                          and rd2.field_name = '$spec->primary_date_field'
                                          and rd2.record = rd.record
                                          and datediff(rd.value, rd2.value) <= $spec->lower_bound
                                          and datediff(rd2.value, rd.value) <= $spec->upper_bound
                                        order by abs(datediff(rd2.value, rd.value)) asc
                                        limit 1)                  as ".$formName."_instance
                                from redcap_data rd,
                                     redcap_metadata rm
                                where rd.project_id = rm.project_id
                                  and rd.project_id = $pid
                                  and rm.field_name = rd.field_name
                                  and rd.field_name = '$spec->foreign_key_field'
                                  and rm.form_name = '$spec->foreign_key_ref') ".$formName."_dproxy
                          where ".$formName."_int.".$formName."_instance = ".$formName."_dproxy.".$formName."_instance
                            and ".$formName."_int.$recordId = ".$formName."_dproxy.$recordId $filter) $formName";
        // as odd as the join clause looks, it's actually correct
        $joinClause = "ON ($formName.$recordId = $spec->foreign_key_ref.$recordId and $formName.".$spec->foreign_key_ref."_instance = $spec->foreign_key_ref.".$spec->foreign_key_ref."_instance)";
        $pieces['joinClause'] = $joinClause;
        $pieces['tableSql'] = $tableSql;
        return $pieces;
    }

    function getTableJoinClause($recordId, $fieldList, $pid, $formName, $filters,  $grouper,  $instanceSelect) {
        $finalSql= "(select * from (select /*line1382*/ rd.record  $recordId,
        $instanceSelect
        $fieldList
      FROM redcap_data rd,
           redcap_metadata rm
      WHERE rd.project_id = rm.project_id
        and rm.field_name = rd.field_name
        and rd.project_id = $pid
        and rm.form_name = '$formName'
      $grouper) t   " . $this->handleFilters($filters, $formName, 'WHERE') . ") $formName";

        return $finalSql  ;
    }

}

