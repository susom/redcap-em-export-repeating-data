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
       //     $this->runTests();
            $result = $this->runQuery(json_decode(json_encode($newConfig)));
            if ($newConfig->preview === 'false') {
                // should export rights also be applied to preview?  Not sure.
                $result = $this->applyUserExportRights($result);
            }
            return $result;
        } catch (Exception $e) {
            $result["status"] = 0 ;
            $result["message"] = $e->getMessage() ;
            return $result ;
        }
    }

    function applyUserExportRights($result) {
        global $module;
        //this doesn't work $user=$module->getUser();
        //this doesn't work either  $module->framework->getUser(USERID)

        $rights=$module->getUserRights();
        $module->emDebug("Rights :" . print_r($rights, TRUE));
        //$module->emDebug("data_export_tool=".$rights['data_export_tool']);
        if (empty($rights) || $rights['data_export_tool'] === '1') {
            // user is superuser or has phi access
            return $result;
        } else if ($rights['data_export_tool'] === '0') {
            //no access
            $result['status']=0;
            $result["message"] = 'User does not have data export access';
            return $result;
        } else if ($rights['data_export_tool'] === '2'
            ||$rights['data_export_tool'] === '3') {
            // 2 == no text, dates or phi
            // 3 == no phi
            $headers=$result['t1']['headers'];
            $data = $result['t1']['data'];
            $dd = $module->getDataDictionary();
            //$module->emDebug("data :" . print_r($data, TRUE));

            $phi_cols=[];
            foreach ($headers as $index=>$header) {
                if (array_key_exists($header, $dd)) {
                    // leave record_id field
                    if ($rights['data_export_tool'] === '2' &&
                        REDCap::getRecordIdField() !== $header &&
                        strpos($dd[$header]['element_type'],'text')!==false) {
                        $phi_cols[$index]=$header;
                    } else if ($dd[$header]['field_phi']==='1') {
                        $phi_cols[$index]=$header;
                    }
                }
            }
            //$module->emDebug("phi_cols[]=".print_r($phi_cols, true));

            if (count($phi_cols)) {
                $filtered_result['status'] = $result['status'];
                $filtered_result['t1']['headers'] = array_diff_key($headers, $phi_cols);
                $filtered_result['t1']['headers'] = array_values($filtered_result['t1']['headers']);
                $filtered_result['t1']['data'] = [];
                foreach ($data as $index => $row) {
                    $diff_result = array_diff_key($row, $phi_cols);
                    $filtered_result['t1']['data'][] = array_values($diff_result);
                }
                return $filtered_result;
            } else {
                return $result;
            }
        }
        $result['status']=0;
        $result["message"] = 'Unrecognized user export rights';
        return $result;
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

//        $module->emDebug("Line 101 Input JSON :" . print_r($json_inp, TRUE));
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
//                    $module->emDebug(print_r($meta,TRUE));
                    $json->forms[$instrument]->cardinality = $meta['cardinality'];
                    $joinType = $json_inp->cardinality->$instrument->join;
                    $json->forms[$instrument]->join_type = $joinType; // this gets overridden

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
                            // $childform is a list of possible matches. Check to see if any of them match the current primary
                            if (strpos($childform, $primaryJoinInstrument)  !== false) {
                                $childform = $primaryJoinInstrument;
                            }
          //                  $module->emDebug('childForm ' . $childform . '  instrument '. $instrument . ' $primaryJoinInstrument ' . $primaryJoinInstrument);
                            if (empty($childform)) {
                                throw new Exception("No parent/child link defined for " . $column->instrument) ;
                            }
                            $meta = $this->instrumentMetadata->isRepeating($childform);
//                            $json->forms[$instrument]->join_key_field =  'instance' ;
                            $json->forms[$instrument]->join_key_field = $meta['foreign_key_field']; // change 1 for case where parent comes first
                            $json->forms[$instrument]->foreign_key_field = $meta['foreign_key_field'] ;
                            $json->forms[$instrument]->foreign_key_ref = $childform ;
                            if (! in_array($meta['foreign_key_field'] ,$json->forms[$childform]->fieldsToJoin)) { // change 2 for case where parent comes first
                                $json->forms[$childform]->fieldsToJoin[] = $meta['foreign_key_field'];
                            }
                        } else {
                            // check if the foreign_key_ref is present in the list of fields to join ; add if missing
                            $f = $json->forms[$instrument];
                            if (!in_array($f->join_key_field, $f->fieldsToJoin)) {
                                $f->fieldsToJoin[] = $f->join_key_field;
                            }
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
//                                        $newjson->forms[$parentPrimaryJoinInstrument]->join_type = 'instance' ;
//                                        $newjson->forms[$parentPrimaryJoinInstrument]->join_key_field = 'instance' ;
//                                        $newjson->forms[$parentPrimaryJoinInstrument]->foreign_key_field = $meta1['foreign_key_field'] ;
//                                        $newjson->forms[$parentPrimaryJoinInstrument]->foreign_key_ref = $childform ;
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
        $json->applyFiltersToData = $json_inp->applyFiltersToData === 'true';
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
    function add_forms_from_filters($json_inp, $json)
    {

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
//                    $f->join_type = 'instance';
//                    $f->join_key_field = $meta['foreign_key_field'];
//                    $f->foreign_key_ref = $meta['foreign_key_ref'];
//                    $f->foreign_key_field = 'instance';
//                    $filter->parent_form = $meta['foreign_key_ref'];
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
                            if (isset($json->forms[$child])) {
                                $childMeta = $this->instrumentMetadata->isRepeating($child);
//                                $json->forms[$filter->instrument]->join_type='instance';
//                                $json->forms[$filter->instrument]->join_key_field = 'instance';
//                                $json->forms[$filter->instrument]->foreign_key_ref = $child;
//                                $json->forms[$filter->instrument]->foreign_key_field = $childMeta['foreign_key_field'];
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

    function add_form_to_spec($newForm, $json)
    {
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
    /* Regression tests */
    function runTests() {
        // $json == 'Input Json'
        // $expected == 'Result Json'
        // Test 1  - demographics with only record_id ticked
        $json = '{"forms":{"demographics":{"fieldsToJoin":["record_id"],"form_name":"demographics","cardinality":"singleton","join_type":"singleton","fieldsToDisplay":["record_id"]}},"raw_or_label":"label","preview":"true","record_count":"false","filters":null}';
        $expected = '{"status":1,"t1":{"headers":["record_id"],"data":[["1"],["2"]]}}';
        $this->testResult(1, $json, $expected);
        // Test 2 - demographics with sex ticked
        $json = '{"forms":{"demographics":{"fieldsToJoin":["sex"],"form_name":"demographics","cardinality":"singleton","join_type":"singleton","fieldsToDisplay":["sex"]}},"raw_or_label":"label","preview":"true","record_count":"false","record_id":"record_id","filters":null}';
        $expected = '{"status":1,"t1":{"headers":["record_id","sex"],"data":[["1","Female"],["2","Male"]]}}';
        $this->testResult(2, $json, $expected);
        // Test 3 - demographics with sex ticked filtered by visit exists
        $json = '{"forms":{"demographics":{"fieldsToJoin":["sex"],"form_name":"demographics","cardinality":"singleton","join_type":"singleton","fieldsToDisplay":["sex"]},"visit":{"form_name":"visit","cardinality":"repeating","fieldsToJoin":["visit_date"],"added_by_filter":true}},"raw_or_label":"label","preview":"true","record_count":"false","record_id":"record_id","filters":[{"field":"visit_date","operator":"EXISTS","boolean":"AND","instrument":"visit","parent_form":"demographics"}]}';
        $expected = '{"status":1,"t1":{"headers":["record_id","sex"],"data":[["1","Female"]]}}';
        $this->testResult(3, $json, $expected);
        // Test 4 - demographics: sex + patientdata: current_clinic + rhcath: date + visit: date w/ visit_date=latest AND whoDx=3 AND clinic=Chest
        $json = '{"forms":{"demographics":{"fieldsToJoin":["sex"],"form_name":"demographics","cardinality":"singleton","join_type":"singleton","fieldsToDisplay":["sex"]},"patientdata":{"fieldsToJoin":["currentclinic"],"form_name":"patientdata","cardinality":"singleton","join_type":"singleton","fieldsToDisplay":["currentclinic"]},"visit":{"fieldsToJoin":["visit_date"],"form_name":"visit","cardinality":"repeating","join_type":"repeating-primary","fieldsToDisplay":["visit_date"]},"rhcath":{"fieldsToJoin":["rhcathdate"],"form_name":"rhcath","cardinality":"repeating","join_type":"date_proximity","lower_bound":999,"upper_bound":999,"primary_date_field":"rhcathdate","foreign_key_ref":"visit","foreign_key_field":"visit_date","fieldsToDisplay":["rhcathdate"]},"workingandwhodx":{"form_name":"workingandwhodx","cardinality":"repeating","added_by_filter":true,"fieldsToJoin":[null]}},"raw_or_label":"label","preview":"true","record_count":"false","record_id":"record_id","filters":[{"field":"visit_date","operator":"MAX","boolean":"AND","instrument":"visit","parent_form":"demographics"},{"field":"whodx","operator":"E","validation":"","param":"3","boolean":"AND","instrument":"workingandwhodx"},{"field":"clinic","operator":"E","validation":"","param":"Chest","boolean":"AND","instrument":"patientdata"}]}';
        $expected = '{"status":1,"t1":{"headers":["record_id","sex","currentclinic","visit_date","rhcathdate"],"data":[["1","Female","Chest","2020-12-08","2020-12-04"]]}}';
        $this->testResult(4, $json, $expected);
        // Test 5 - visit followed by specificmed
        $json = '{"forms":{"visit":{"fieldsToJoin":["visit_date"],"form_name":"visit","cardinality":"repeating","join_type":"repeating-primary","fieldsToDisplay":["visit_date"]},"specificmed":{"fieldsToJoin":["specificmed_visit_id","specific_med"],"form_name":"specificmed","cardinality":"repeating","join_type":"instance","join_key_field":"specificmed_visit_id","foreign_key_field":"instance","foreign_key_ref":"visit","fieldsToDisplay":["specific_med"]}},"raw_or_label":"label","preview":"true","record_count":"false","record_id":"record_id","filters":null}';
        $expected = '{"status":1,"t1":{"headers":["record_id","visit_date","specific_med"],"data":[["1","2020-08-12","Acai berry"],["1","2020-09-12","Carisoprodol"],["1","2020-09-12","Chondroitin"],["1","2020-12-08","Abacavir"],["1","2020-12-08","Baclofen"],["1","2020-12-08","Calcium"]]}}';
        $this->testResult(5, $json, $expected);
        // Test 6 - specificmed followed by visit
        $json = '{"forms":{"specificmed":{"fieldsToJoin":["specific_med","specificmed_visit_id"],"form_name":"specificmed","cardinality":"repeating","join_type":"repeating-primary","fieldsToDisplay":["specific_med"]},"visit":{"fieldsToJoin":["visit_date"],"form_name":"visit","cardinality":"repeating","join_type":"instance","join_key_field":"specificmed_visit_id","foreign_key_field":"specificmed_visit_id","foreign_key_ref":"specificmed","fieldsToDisplay":["visit_date"]}},"raw_or_label":"label","preview":"true","record_count":"false","record_id":"record_id","filters":null}';
        $expected = '{"status":1,"t1":{"headers":["record_id","specific_med","visit_date"],"data":[["1","Acai berry","2020-08-12"],["1","Acai berry","2020-09-12"],["1","Acai berry","2020-12-08"],["1","Carisoprodol","2020-08-12"],["1","Carisoprodol","2020-09-12"],["1","Carisoprodol","2020-12-08"]]}}';
        $this->testResult(6, $json, $expected);
        // Test 7 visit followed by rhcath
        $json = '{"forms":{"visit":{"fieldsToJoin":["visit_date"],"form_name":"visit","cardinality":"repeating","join_type":"repeating-primary","fieldsToDisplay":["visit_date"]},"rhcath":{"fieldsToJoin":["rhcathdate"],"form_name":"rhcath","cardinality":"repeating","join_type":"date_proximity","lower_bound":999,"upper_bound":999,"primary_date_field":"rhcathdate","foreign_key_ref":"visit","foreign_key_field":"visit_date","fieldsToDisplay":["rhcathdate"]}},"raw_or_label":"label","preview":"true","record_count":"false","record_id":"record_id","filters":null}';
        $expected = '{"status":1,"t1":{"headers":["record_id","visit_date","rhcathdate"],"data":[["1","2020-08-12","2020-07-12"],["1","2020-09-12","2020-07-12"],["1","2020-12-08","2020-12-04"]]}}';
        $this->testResult(7, $json, $expected);
        // Test 8 visit + specificmed + rhcath
        $json = '{"forms":{"visit":{"fieldsToJoin":["visit_date"],"form_name":"visit","cardinality":"repeating","join_type":"repeating-primary","fieldsToDisplay":["visit_date"]},"specificmed":{"fieldsToJoin":["specificmed_visit_id","specific_med"],"form_name":"specificmed","cardinality":"repeating","join_type":"instance","join_key_field":"specificmed_visit_id","foreign_key_field":"instance","foreign_key_ref":"visit","fieldsToDisplay":["specific_med"]},"rhcath":{"fieldsToJoin":["rhcathdate"],"form_name":"rhcath","cardinality":"repeating","join_type":"date_proximity","lower_bound":999,"upper_bound":999,"primary_date_field":"rhcathdate","foreign_key_ref":"visit","foreign_key_field":"visit_date","fieldsToDisplay":["rhcathdate"]}},"raw_or_label":"label","preview":"true","record_count":"false","record_id":"record_id","filters":null}';
        $expected = '{"status":1,"t1":{"headers":["record_id","visit_date","specific_med","rhcathdate"],"data":[["1","2020-08-12","Acai berry","2020-07-12"],["1","2020-09-12","Carisoprodol","2020-07-12"],["1","2020-09-12","Chondroitin","2020-07-12"],["1","2020-12-08","Abacavir","2020-12-04"],["1","2020-12-08","Baclofen","2020-12-04"],["1","2020-12-08","Calcium","2020-12-04"]]}}';
        $this->testResult(8, $json, $expected);
    }
    function testResult($testNumber, $json , $expected) {
        global $module;
        $result = json_encode($this->runQuery(json_decode($json)));
        $module->emDebug( $result);
        $module->emDebug( $expected);
        if ($result == $expected) {
            $module->emDebug( $testNumber . " test passed");
        } else {
            $module->emDebug( $testNumber . " test FAILED");
        }

    }

    // used when counting the number of patients (user clicked 'count' in filter panel)
    function generateWhereClauseFromFilters($json, $project_id, $valSel, $selectClause) {
        global $module;
        $sql = $selectClause . " FROM redcap_data rdm where rdm.project_id = " . $project_id . " AND ";
        if (is_array($json->filters) || is_object($json->filters)) {
            foreach ($json->filters as $filterIdx => $filter) {
                // for now ignore max and min in doing counts.  will need to think about how to do this in combo
                // with other parameters
                if ($filter->operator != 'MAX' && $filter->operator != 'MIN') {

                    $filterstr = $this->filter_string($filter);

                    $filter_val_sel = "(case when rd.field_name = '" . $filter->field . "' and (rm.element_type = 'calc' or coalesce(rm.element_enum, '') = '') then rd.value " .
                        " when rd.field_name = '" . $filter->field . "' then $valSel end) " ;

                    $filterstr = str_replace( $filter->field, $filter_val_sel, $filterstr);
                    $sql = $sql . " rdm.record in (select record from redcap_data rd, redcap_metadata rm where rd.project_id = rm.project_id and rd.field_name = rm.field_name "
                        . $this->getDagFilter('rd', $project_id)
                        . " and rd.project_id = " . $project_id
                        . " and rd.field_name = '" . $filter->field
                        . "' and " . $filterstr . ") " . " " . $filter->boolean . " ";

                }
            }
        }

        if (substr($sql, -4) == "AND ")
            $sql = substr($sql, 0, strlen($sql) - 4);

        if (substr($sql, -3) == "OR ")
            $sql = substr($sql, 0, strlen($sql) - 3);

        $module->emDebug("SQL for COUNT : " . $sql);

        return $sql;
    }

    /**
     * This is the function that takes the Json coming from the client and converts it into SQL,
     * executes the SQL, and returns the restuls to the client
     * There are two main paths
     * 1.  the client is asking just for a count of patients matching a single filter, and
     * 2.  the client has clicked either Preview or Export, and wants an actual report
    */
    function runQuery($json)
    {
        global $module;

        $module->emDebug("Input Json :" . json_encode($json)) ;

        // look up the default row limit and set to 200 if not otherwise specified in the EM config
        $rowLimit = $module->getProjectSetting('preview-record-limit');
        if (!isset($rowLimit)) {
            $rowLimit = 200;
        }

        $valSel = "rd.value";

        $project_id = $this->Proj->project_id;

        // record count feature - return this every time the end-user interacts with a button
        // not just when they click the "count" button in the filter panel
    
        $sql = "select count(distinct rdm.record) as row_count " ;
        $sql = $this->generateWhereClauseFromFilters($json, $project_id, $valSel, $sql);

        $result1 = db_query($sql);

        $row = db_fetch_assoc($result1);

        $result["count"] = $row["row_count"];
        
        // if the user is asking for just counts, return. Otherwise they are asking for data , so carry on
        if ($json->record_count === 'true') {
            return $result;
        }
        // Keep the order of the fields as specified by the user
        $select_fields = array();  // Fields which will be returned to the caller

        // whether or not the user has explicitly asked for the record_id, we always need to return it, as
        // it is the primary indication of which patient the data is associated with.
        // Also it is hyperlinked to the underlying record in the preview
        $recordFieldIncluded = !isset($json->record_id);
        if (! $recordFieldIncluded) {
            array_unshift($select_fields, $json->record_id);   // add record_id as the first field in the fields array
        }

        foreach ($json->forms as $form) {
            // Doing coalesce so nulls will be displayed as '' in output reports
            foreach ($form->fieldsToDisplay as $field) {
                $select_fields[] = $field;
            }
        }

        define("BASIC", 0);
        define("TEMP_TABLE_DEFN", 1);
        define("TEMP_TABLE_USE", 2);
        // generate the SQL
        if ($json->applyFiltersToData) {
            $sql = $this->getSqlMultiPass($json, $this->Proj->project_id, BASIC);
        } else {
            $sql1 = "CREATE TEMPORARY TABLE fred as " . $this->getSqlMultiPass($json, $this->Proj->project_id, TEMP_TABLE_DEFN); // create temporary table
            db_query($sql1) or die ("Sql error : ".db_error());
            $module->emDebug($sql1);
            $sql = $this->getSqlMultiPass($json, $this->Proj->project_id, TEMP_TABLE_USE); // select w/ join on temporary table

        }
        // append a limit clause if they are asking for a preview rather than a data export
        if ("true" == $json->preview && strlen(trim($sql)) > 0) {
            $sql = $sql . " LIMIT " . $rowLimit;
        }

        $module->emDebug($sql);

        if (strlen(trim($sql)) > 0) {
            // actually execute the sql - this is where the magic happens!
            $rptdata = db_query($sql);
            
            $result["status"] = 1; // when status = 0 the client will display the error message
            if (strlen(db_error()) > 0) {
                $dberr = db_error();
                error_log($dberr);
                $module->emlog($dberr);
                $result["status"] = 0;
                $result["message"] = $dberr;
            } else {
                $result["t1"]  = $this->package($json->preview, $select_fields, $rptdata, $json->raw_or_label == 'label');
            }
            if (! $json->applyFiltersToData) {
                db_query("DROP TEMPORARY TABLE fred");
            }
        } else {
            $result["status"] = 0;
            $result["message"] = "No data requested. You must specify at least one column";
        }
        $module->emDebug('Result Json '.json_encode($result));
        return $result;
    }

    // transform the results returned by the SQL query by adding separate columns for each checkbox value
    // and by replacing codes with labels if so requested by the end-user
    function package($preview, $select_fields, $rptdata, $showLabel)
    {
        global $module;
        $data = [];
        $hdrs = $this->pivotCbHdr($select_fields);
        if ("false" == $preview) {
            // when exporting .csv, the return csv is in $data
            $data[] = $hdrs;  // $headers;
        }
        while ($row = db_fetch_assoc($rptdata)) {
            $module->emDebug(print_r($row,TRUE));
            $cells = [];
            for ($k = 0; $k < count($select_fields); $k++) {

                $cells = array_merge($cells, $this->pivotCbCell($select_fields[$k], $row[$select_fields[$k]], $showLabel, $hdrs[$k])) ; //, $data[$k]
            }
            $data[] = $cells;
        }
        // when previewing, the return data is in $result, which includes $data
        $result["headers"] = $this->pivotCbHdr($select_fields);
        $result["data"] = $data;
        return $result;
    }

    // used to determine whether a field is a date
    function endsWith($string, $endString)
    {
        $len = strlen($endString);
        if ($len == 0) {
            return true;
        }
        return (substr($string, -$len) === $endString);
    }

    // this is where the Json for filters gets converted into SQL
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
            $filterstr = ($dt == "string") ? ($col . " = '" . $val."'") : ($col . " = " . $val);
        elseif ($filter->operator == "NE")
            $filterstr = ($dt == "string") ? ($col . " <> '" . $val."'") : ($col . " <> " . $val);
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
            // self join to pick up the max/min for the date in the instrument specified by the filter
            $filterstr = $col . " = (select " . $filter->operator . "(rdx.value) from redcap_data rdx, redcap_metadata rmx
          where rdx.project_id  = rmx.project_id and rmx.field_name  = rdx.field_name and rdx.project_id  = "
                . $this->Proj->project_id
                . $this->getDagFilter('rdx', $this->Proj->project_id)
                . " and rdx.field_name = '" . $filter->field . "' and rdx.record=t." . REDCap::getRecordIdField() . ")";

        return $filterstr;
    }

    // called indirectly by package() which adds column names to the header for checkbox fields
    private function isCheckbox($field)
    {
        return $this->Proj->metadata[$field]['element_type'] === 'checkbox';
    }

    // called indirectly by package() which adds column names to the header for checkbox fields
    private function cbLov($field)
    {
        return $this->Proj->metadata[$field]['element_enum'];
    }

    // called by package(); adds column names to the header for checkbox fields
    private function pivotCbHdr($headers)
    {
        $newHeaders = [];
        foreach ($headers as $field) {
            if ($this->isCheckbox($field)) {
                $lovstr = $this->cbLov($field);
                $lov = explode("\\n", $lovstr);
                for ($i = 1; $i < count($lov) + 1; ++$i) {
                    $newHeaders[] = $field . '___' . $i;
                }
            } else {
                $newHeaders[] = $field;
            }
        }

        return $newHeaders;
    }

    // called by package(); adds column values to each row of data for checkbox fields
    // and converts values to labels
    private function pivotCbCell($field, $cellValue, $showLabel, $fieldName)
    {
        $newCells = [];
        $loSetValues = explode("\n", $cellValue);
        if ($showLabel) {
            $lovMeta = $this->instrumentMetadata->getValue($field . '@lov');
        }
        
        if ($this->isCheckbox($field)) {
            $lovstr = $this->cbLov($field);
            $lov = explode("\\n", $lovstr);
            
            for ($i = 0; $i < count($lov); ++$i) {
                $found = false;
                // now look in loSetValues for the index
                for ($j = 0; $j < count($loSetValues); ++$j) {
                    // consider each possible value from the data dictionary in turn
                    if (strpos(trim($lov[$i]), trim($loSetValues[$j])) === 0) {
                        $newCells[] = $this->getValueOrLabel(trim($loSetValues[$j]), $lovMeta, $showLabel);
                        $found = true;
                    }
                }
                if (! $found) {
                    $newCells[] = '';
                }
            }
        } else {
            $newCells[] = $this->getValueOrLabel($cellValue, $lovMeta, $showLabel);
        }

        return $newCells;
    }

    function startsWith ($string, $startString)
    {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }

    public function getValueOrLabel($cellValue, $lov, $showLabel) {
        if (! $cellValue) {
            return $cellValue;
        }
        if ($showLabel) {
            $arLov = explode("\\n", $lov);
            foreach($arLov as $value) {
                $value = trim($value);
                if ($this->startsWith($value, $cellValue)) {
                    return trim(substr($value, strlen($cellValue) + 1));
                }
            }
            return $cellValue;
        }
        return $cellValue;

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

    // processing loop for invoking filter_string(), the function that does all the actual work
    // results are aggregated and returned as a single SQL fragment
    function handleFilters($filters, $formName, $prefix, $applyFiltersToData)
    {

        if (! $applyFiltersToData) {
            return "";
        }
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

    // this coalesce pattern is used throughout; it replaces NULL with empty string ,
    // resulting in much nicer looking reports
    function getFields($instrument, $fields, $mode)
    {
        $fieldstr = "";
        $cnt = 0;
        if (is_array($fields) || is_object($fields)) {
            foreach ($fields as $field) {
                if ($cnt > 0) {
                    $fieldstr .= ", ";
                }
                $fieldstr .= "COALESCE (".$instrument."_a.".$field.",'') `$field`"; // append _a to table alias in case the table name is a SQL reserved word
                $cnt++;
                if ($mode == TEMP_TABLE_DEFN) {
                    break; // only need record_ids in the temp table
                }
            }
        }

        return $fieldstr;
    }

    // turn the query specification in Json into SQL
    // we use multiple passes in order to separate out changes in table ordering that impact the SQL from
    // table ordering that should be ignored. For example, all fields in singleton (non-repeating)
    // instruments should be inner joined to the primary repeating form.
    function getSqlMultiPass($json, $pid, $mode)
    {
        global $module;
        $recordId =  REDCap::getRecordIdField();
        $finalSql = "";

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
            $spec = $json->forms->$formName;
            $selectClause = "$formName"."_a.$recordId, ".$formName."_instance, ";
            $selectClause .= $this->getFields($formName, $spec->fieldsToDisplay, $mode);
            $fieldNames = $this->augment($spec->form_name, $spec->fieldsToJoin, $filters);
            $inlineTable = $this->getInlineTableSql($fieldNames, $spec->form_name, $pid, $spec, $filters, $mode);
            foreach ($instances as $childForm) {
                $spec = $json->forms->$childForm;
                if (count($spec->fieldsToDisplay) > 0) {
                    $selectClause .= ", /*1147 */" . $this->getFields($childForm, $spec->fieldsToDisplay, $mode);
                }
                $fieldNames = $this->augment($spec->form_name, $spec->fieldsToJoin, $filters);
                $inlineTable .= $this->getJoin($childForm, $filters, $json);
                $inlineTable .= $this->getInlineTableSql($fieldNames, $spec->form_name, $pid, $spec, $filters, $mode);
                $jk = ($spec->join_key_field == 'instance' ? $spec->form_name . "." . $spec->form_name . "_instance" :  $spec->join_key_field);
                $fk = $spec->foreign_key_ref;
                $inlineTable .= " ON ".$formName."_a.$recordId=$spec->form_name"."_a.$recordId AND /*1*/ $jk = " . $fk . "_instance";
            }
            $inlineTable = "(select  $selectClause  FROM ( $inlineTable ) ) $formName" . "_a" ;
            $tablePivots[$formName] = $inlineTable;

        }
//        $module->emDebug('$inlineTable :' . print_r($tablePivots, TRUE));
        $priorTable = '';
        $selectClause = $this->getSelectClause($json, $mode); // this counts as pass #2
        $cntTableJoins = 0;
        // the third pass picks up all singleton forms
        foreach ($json->forms as $formName => $spec) {
            if ($spec->join_type != 'singleton') {
                continue;
            }

            $finalSql .= $this->getInterimSql( $json, $pid, $cntTableJoins, $formName, $spec, $filters, $tablePivots, $priorTable, $mode);
            $cntTableJoins ++;
            $priorTable = $formName;

        }
        // ok, now in this pass (#4), skip over forms with join_type instance
        // and also skip forms that already have their SQL generated
        // and re-create a complete select list for the outer layer

//        $cntTableJoins = 0;
        foreach ($json->forms as $formName => $spec) {

//            $module->emDebug('$selectClause ' . print_r($selectClause,TRUE));
            // carry on generating the remaining table pivots and joins
            if ($spec->join_type == 'singleton' || $spec->join_type == 'instance' ) {
                continue;
            }

            $finalSql .= $this->getInterimSql( $json, $pid, $cntTableJoins, $formName, $spec, $filters, $tablePivots, $priorTable, $mode);
            // date_proximity has the join hard-coded; see getDateProximityTableJoin()
            $cntTableJoins ++;
            $priorTable = $formName;
        }
        $finalSql =  $selectClause . $finalSql ;
        if ($mode == TEMP_TABLE_USE) {
            $finalSql = $finalSql . " INNER JOIN fred on fred.$recordId = " . $priorTable . "_a.$recordId";

        }
        return $finalSql;
    }

    function getInterimSql( $json, $pid, $cntTableJoins, $formName, $spec, $filters, $tablePivots, $priorTable, $mode)
    {
        global $module;
        $recordId = REDCap::getRecordIdField();
        $innerTableSql = "";
        $joinClause = "";
        if ($cntTableJoins > 0) {
            if ($spec->join_type == 'date_proximity') {
                $finalSql = " LEFT OUTER JOIN ";
            } else {
                $finalSql = $this->getJoin($formName, $filters, $json);
            }
        }
        if (strlen($tablePivots[$formName]) > 0) {
            $innerTableSql = $tablePivots[$formName];
        }
        // singleton or date_proximity
        $spec= $json->forms->$formName;
        $fieldNames = $this->augment($spec->form_name, $spec->fieldsToJoin, $filters);
        if ($spec->join_type == 'date_proximity') {
            if (strlen($innerTableSql) == 0) {
                $innerTableSql = $this->getInnerTableSql($spec->form_name, $this->getFieldList($fieldNames), $pid);
            } else {
                // Remove the table alias from the end of this string
                // when $tablePivots $innerTableSql used in this context
                $innerTableSql = $this->str_lreplace($formName . "_a" , '', $innerTableSql);
            }
            $pieces = $this->getDateProximityTableJoin($spec->form_name, $innerTableSql, $spec, $pid, $filters, $mode);
            $finalSql .= $pieces['tableSql'];
            $joinClause .= $pieces['joinClause'];
        } else {
//            $module->emDebug('$innerTableSql :' . print_r($innerTableSql, TRUE));
            if (strlen($innerTableSql) == 0) {
                $innerTableSql = $this->getInlineTableSql($fieldNames, $spec->form_name, $pid, $spec, $filters, $mode);
            }
            $finalSql .= $innerTableSql;
        }

        if ($cntTableJoins > 0) {
            if ($spec->join_type != 'date_proximity') {
                $finalSql .= " ON $formName"."_a.$recordId=$priorTable"."_a.$recordId ";
            } else {
                $finalSql .= " " . $joinClause;
            }
        }
        return $finalSql;
    }

    // generate the top level select clause for all specified field and instruments
    // this is required because the inline tables all have additional fields used as join fields
    // that need to be filtered out of the report returned to the end-user
    function getSelectClause($json, $mode)
    {
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
                $selectClause .= $this->getFields($spec->foreign_key_ref, $fieldsToDisplay, $mode);
                // now that we've picked up their fields to display we're done,
                // as the from clause has already been incorporated into the SQL cached in the earlier pass
                continue;
            } else {
                $selectClause .= $this->getFields($formName, $fieldsToDisplay, $mode);
            }
            if ($mode == TEMP_TABLE_DEFN) { // just looking for record_id in the temp table
                break;
            }
        }
        return "select distinct " . $selectClause . " FROM " ;
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

    function getJoin($formName, $filters, $json) {
        global $module;
        // if there is a matching supplied filter, use inner join, because that's the semantics of a filter
        // otherwise use outer join, so you don't lose rows from the parent if the child table is missing data
        foreach ($filters as $spec) {
            if ($spec->instrument == $formName) {
                return " INNER JOIN ";
            }
        }
        // the other reason to return inner join is that the prior table is the driver table for the report
        foreach ($json->forms as $formName => $spec) {
            $firstForm = $formName;
            break;
        }
        if ($formName == $firstForm && $json->forms->$formName->cardinality != 'singleton') {
           return " INNER JOIN ";
        }
        return " LEFT OUTER JOIN ";
    }

    function augment($formName, $fieldsToJoin, $filters)
    {
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

    function getFieldList($fieldNames)
    {
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
//            $fieldList .= "group_concat(distinct case when rd.field_name = '$fieldName' and (rm.element_type = 'calc' or coalesce(rm.element_enum, '') = '') then rd.value
//                                   when rd.field_name = '$fieldName' then coalesce(SUBSTRING_INDEX(trim(substring(element_enum, instr(element_enum, concat('\\\\n',concat(rd.value, ','))) + length(rd.value) + 3)),'\\\\n', 1), rd.value)
//                              end
//                              separator '\\n') `$fieldName`";
            $fieldList .= "group_concat(distinct case when rd.field_name = '$fieldName'  then rd.value
                              end
                              separator '\\n') `$fieldName`";
        }
        return $fieldList;
    }

    function getInlineTableSql($fieldNames, $formName, $pid, $spec, $filters, $mode)
    {
        //  build up the list of field names for the current instrument
        $fieldList = $this->getFieldList($fieldNames);

//        global $module;
//
//        $module->emDebug('getInlineTableSql $fieldList :' . print_r($fieldList, TRUE));

        if ($spec->cardinality == "singleton") {
            $instanceSelect = "";
            $grouper = " GROUP BY rd.record";
        } else {
            $instanceSelect = "COALESCE(rd.`instance`, 1) as ".$formName."_instance,";
            $grouper = " GROUP BY rd.record, ".$formName."_instance";
        }

        return $this->getTableJoinClause( REDCap::getRecordIdField(), $fieldList, $pid, $formName, $filters, $grouper, $instanceSelect, $mode);
    }

    // use case: 1. rhcath 2. visit 3. workingdx
    // i.e. date pivot on a table that is the parent in a parent-child relationship
    function getInnerTableSql($formName, $fieldList,  $pid)
    {
        $recordId =  REDCap::getRecordIdField();
            return "select  rd.record as $recordId,
                        COALESCE(rd.instance, '1') " . $formName . "_instance,
                        $fieldList
                                FROM redcap_data rd,
                                     redcap_metadata rm
                                WHERE rd.project_id = rm.project_id
                                  and rm.field_name = rd.field_name
                                  and rd.project_id = $pid
                                  and rm.form_name = '$formName'".
                                  $this->getDagFilter('rd',$pid).
                                "GROUP BY rd.record, rd.instance";

    }

    function getDagFilter($prefix, $pid) {
        global $module;
        $userRights = $module->getUserRights();
        if ( !empty($userRights['group_id']) && $userRights['group_id']!==0) {
            //$module->emDebug('Group ID:' . $userRights['group_id']);
            return " and ".$prefix.".record in (select record 
                                FROM redcap_record_list rl
                                WHERE rl.dag_id = ".$userRights['group_id'].
                                " and rl.project_id = $pid) ";
        }
        return "";
    }

    function getDateProximityTableJoin($formName, $innerTableSql, $spec, $pid, $filters, $mode)
    {
        $recordId =  REDCap::getRecordIdField();
        $filter = $this->handleFilters($filters, $formName, 'AND', $mode != TEMP_TABLE_USE);
        $tableSql =  "(Select distinct ".$formName."_int.*, t.".$spec->foreign_key_ref."_instance
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
                                  and rm.field_name = rd.field_name ".
                                    $this->getDagFilter('rd', $pid).
                                  " and rd.field_name = '$spec->foreign_key_field'
                                  and rm.form_name = '$spec->foreign_key_ref') t
                          where ".$formName."_int.".$formName."_instance = t.".$formName."_instance
                            and ".$formName."_int.$recordId = t.$recordId $filter) $formName" . "_a";
        // as odd as the join clause looks, it's actually correct
        $joinClause = "ON ($formName"."_a.$recordId = $spec->foreign_key_ref"."_a.$recordId and $formName"."_a.".$spec->foreign_key_ref."_instance = $spec->foreign_key_ref"."_a.".$spec->foreign_key_ref."_instance)";
        $pieces['joinClause'] = $joinClause;
        $pieces['tableSql'] = $tableSql;
        return $pieces;
    }

    function getTableJoinClause($recordId, $fieldList, $pid, $formName, $filters,  $grouper,  $instanceSelect, $mode)
    {
        $comma = (strlen($instanceSelect) > 0 || strlen($fieldList) > 0 ? ',' : '');
        $finalSql= "\n(select * from (select /*line1382*/ rd.record  $recordId $comma
        $instanceSelect
        $fieldList
      FROM redcap_data rd,
           redcap_metadata rm
      WHERE rd.project_id = rm.project_id
        and rm.field_name = rd.field_name ".
            $this->getDagFilter('rd', $pid)
            ." and rd.project_id = $pid
        and rm.form_name = '$formName'
      $grouper) t   " . $this->handleFilters($filters, $formName, 'WHERE', $mode != TEMP_TABLE_USE) . ") $formName"."_a";

        return $finalSql  ;
    }

}

