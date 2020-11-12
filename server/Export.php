<?php

namespace Stanford\ExportRepeatingData;
/** @var ExportRepeatingData $module */
use REDCap;

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
        $newConfig = $this->assembleSpecification($config);
        $result = $this->runQuery($newConfig);
        return $result;
    }

    public function saveTempFile($content)
    {
        return file_put_contents($this->getTempFilePath(), $content);;
    }

    function assembleSpecification($config)
    {
        global $module;

        // oddly enough this operation is not idempotent. the incoming arrays get converted into objects
        $json_inp = json_decode(json_encode($config));

        $module->emDebug("Input JSON :" . print_r($json_inp, TRUE));

        $json = json_decode('{ "forms" : []}');
        $json->raw_or_label = $json_inp->raw_or_label;
        // look through the incoming spec for the primary-repeating form, since that will be the join key
        // used by any repeating-date-pivot references. There can be only one...
        if (is_array($json_inp->cardinality) || is_object($json_inp->cardinality)) {
            foreach ($json_inp->cardinality as $instrument => $value) {
                if ($value->join === 'repeating-primary') {
                    $primaryJoinInstrument = $instrument;
                    $meta = $this->instrumentMetadata->isRepeating($instrument);
                    $primaryJoinField = $meta['principal_date'];
                    break;
                }
            }
        }
//        $module->emDebug('$json_inp is '.print_r($json_inp,TRUE));
        //  if record_id is missing, add it. Every report needs the record_id
        // look up name of record_id

        // this seems like an awkward way to do this.
        // why not use $identifier_field = REDCap::getRecordIdField()
        // or $identifier_field = array_key_first($this->Proj->metadata) ?

        foreach ($this->Proj->metadata as $identifier_field => $firstRecord) {
            break;
            // $identifier_field is now correctly set. it is referenced below
        }
        // $record_identifier now contains 'record_id' or whatever the first field is named
        // now look for it in the first pass
        $found_record_identifier = false;

        // stash the preview setting, the SQL generation step needs to know
        $json->preview = $json_inp->preview;  // $config['preview'];

        if (is_array($json_inp->columns)) {

            foreach ($json_inp->columns as $column) {
                $instrument = $column->instrument;
                if (!isset($json->forms[$instrument])) {
                    $json->forms[$instrument] = json_decode('{ "fields" : [] }');
                    $json->forms[$instrument]->form_name = $column->instrument;

                    $meta = $this->instrumentMetadata->isRepeating($column->instrument);
                    $json->forms[$instrument]->cardinality = $meta['cardinality'];
                    $joinType = $json_inp->cardinality->$instrument->join;
                    if ($joinType == 'repeating-instance-select') {
                        $json->forms[$instrument]->join_type = 'instance';
                        $json->forms[$instrument]->foreign_key_ref = $meta['foreign_key_ref'];
                        $json->forms[$instrument]->foreign_key_field = $meta['foreign_key_field'];
                    } else if ($joinType == 'repeating-date-pivot') {
                        $json->forms[$instrument]->join_type = 'date_proximity';
                        $json->forms[$instrument]->lower_bound = $json_inp->cardinality->$instrument->lower_bound;
                        $json->forms[$instrument]->upper_bound = $json_inp->cardinality->$instrument->upper_bound;
                        $json->forms[$instrument]->primary_date_field =
                            $json_inp->cardinality->$instrument->primary_date;
                        if (!empty($primaryJoinField)) {
                            $json->forms[$instrument]->foreign_key_ref = $primaryJoinInstrument;
                            $json->forms[$instrument]->foreign_key_field = $primaryJoinField;
                        } else {
                            // if there's no join field then need to join with this form's parent
                            $meta = $this->instrumentMetadata->isRepeating($primaryJoinInstrument);
                            $json->forms[$instrument]->foreign_key_ref = $meta['foreign_key_ref'];
                            // add the missing parent form
                            $this->add_form_to_spec($meta['foreign_key_ref'], $json);
                            // the parent's principal date is the foreign key field for date proximity join
                            $parentMeta = $this->instrumentMetadata->isRepeating($meta['foreign_key_ref']);
                            $json->forms[$instrument]->foreign_key_field = $parentMeta['principal_date'];
                        }
                    }
                }
                $json->forms[$column->instrument]->fields[] = $column->field;
                $found_record_identifier = $found_record_identifier || ($column->field === $identifier_field);
                if (!$found_record_identifier) {
                    $json->record_id = $identifier_field;
                }
            }
        }
        $this->add_forms_from_filters($json_inp, $json);
        $json->filters = $json_inp->filters;
        $json->record_count = $json_inp->record_count;
        $module->emDebug('final json ' . print_r($json, true));
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

                if ($is_child) {
                    $json->forms[$filter->instrument]->join_type = 'instance';
                    $json->forms[$filter->instrument]->foreign_key_ref = $meta['foreign_key_ref'];
                    $json->forms[$filter->instrument]->foreign_key_field = $meta['foreign_key_field'];
                    $filter->parent_form = $meta['foreign_key_ref'];
                    // check if the foreign_key_ref is one of the forms
                    if (isset($json->forms[$filter->parent_form]) &&
                        !in_array($meta['foreign_key_field'], $json->forms[$filter->instrument]->fields)) {
                        $json->forms[$filter->instrument]->fields[]=$meta['foreign_key_field'];
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
                                $json->forms[$child]->join_type='instance';
                                $json->forms[$child]->foreign_key_ref = $filter->instrument;
                                $json->forms[$child]->foreign_key_field = $childMeta['foreign_key_field'];
                                //add the foreign key field if it's not already there
                                if (!in_array($childMeta['foreign_key_field'], $json->forms[$child]->fields)) {
                                    $json->forms[$child]->fields[] = $childMeta['foreign_key_field'];
                                }
                            }
                        }
                    }
                }
            }
            // if we've just added a form with a parent form, then the parent form also needs to be joined
            if (isset($filter->parent_form)
                && !array_key_exists($filter->parent_form, $json->forms)) {
                $this->add_form_to_spec($filter->parent_form, $json);
            }
        }
    }

    function add_form_to_spec($newForm, $json) {
        $meta = $this->instrumentMetadata->isRepeating($newForm);
        $json->forms[$newForm]->form_name = $newForm;
        $json->forms[$newForm]->cardinality = $meta['cardinality'];
        if (isset($meta['principal_date'])) {
            $json->forms[$newForm]->fields[] = $meta['principal_date'];
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
        $select = "";
        $from = "";

        // record count feature - as explained by Susan
        if ($json->record_count === 'true') {

            $sql = "select count(distinct rdm.record) as row_count from redcap_data rdm where rdm.project_id = " . $project_id . " AND ";

            if (is_array($json->filters) || is_object($json->filters)) {
                foreach ($json->filters as $filterIdx => $filter) {
                    // for now ignore max and min in doing counts.  will need to think about how to do this in combo
                    // with other parameters
                    if ($filter->operator != 'MAX' || $filter->operator == 'MIN') {
                        $filterstr = $this->filter_string($filter);

                        $filterstr = str_replace($filter->instrument . '.' . $filter->field, $valSel, $filterstr);

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

            $module->emDebug("SQL for COUNT : " . $sql);

            $result1 = db_query($sql);

            $row = db_fetch_assoc($result1);

            $result["count"] = $row["row_count"];
            return $result;
        }

        $select_fields = array();  // Fields which will be returned to the caller
        $all_fields = array();  // fields including the filters in the selected instruments

        $recordFieldIncluded = !isset($json->record_id);
        $module->emDebug('is record_id included? ' . $recordFieldIncluded . ' ' . $json->record_id);
        $primaryFormName = "";

        // Keep the order of the fields as specified by the user
        foreach ($json->forms as $form) {
            // Doing coalesce so nulls will be displayed as '' in output reports
            foreach ($form->fields as $field) {
                $select_fields[] = $field;
                $all_fields[] = $field;
                $select = $select . (($select == "") ? " " : ", ") . "COALESCE(" . $field . ", '') " . $field;
            }
        }

        #$module->emDebug("form before rearranging :" . print_r($json->forms, TRUE)) ;
        // Make the first repeating form as the primary - SDM-107
        // Best will be to let the user decide which one is the primary - later
        /*foreach ($json->forms as $form) {
                if ($form->cardinality === 'repeating') {
                    $json->forms = [$form->form_name => $form] + $json->forms;
                    break;
                }
        }*/
        // forms need to be sorted so that parent repeating forms go before child repeating forms.
        // singletons go last?
        // TODO: ask Srini about user deciding on order of forms
        uasort($json->forms, function ($a, $b) {
            if (strcmp($a->cardinality,$b->cardinality) === 0) {
                if (!isset($a->join_type) && !isset($b->join_type)) {
                    return 0;
                } else {
                    //unset < join_type === date_proximity < join_type === instance
                    //add different join types later?
                    if (!isset($a->join_type)) { return -1;}
                    if (!isset($b->join_type)) { return 1;}
                    return (strcmp($a->join_type, $b->join_type));
                }
            }
            // repeating < singleton
            return strcmp($a->cardinality,$b->cardinality);
        });

        $module->emDebug("form after rearranging :" . print_r($json->forms, TRUE)) ;

        foreach ($json->forms as $formIdx => $form) {

            // To identify the key instrument - first instrument is considered primary and all other instruments will
            // be joined as left outer joins  - sorting is done in earlier loop

            $primaryForm = ($from == "");
            if ($primaryForm) {
                $primaryFormName = $form->form_name;
            }

            // mapping null instance to '1'
            $formSql = (($form->cardinality == 'singleton') ? " ( select rd.record " : " ( select rd.record, COALESCE(rd.instance, '1') instance ");

            // Converting redcap_data into a view format for each selected fields
            foreach ($form->fields as $field) {
                // changed from max to group_contact to handle checkbox values - SDM-109
                $formSql = $formSql . ", group_concat(distinct case when rd.field_name = '" . $field . "' then $valSel end separator '\\n') " . $field . " ";
            }

            // Add to the view, if it is included in the filters also
            if (is_array($json->filters) || is_object($json->filters)) {
                foreach ($json->filters as $filter) {
                    if ($filter->instrument == $form->form_name && !in_array($filter->field, $select_fields)) {
                        $all_fields[] = $filter->field;
                        $formSql = $formSql . ", group_concat(distinct case when rd.field_name = '" . $filter->field . "' then $valSel end separator '\\n') " . $filter->field . " ";
                    }
                }
                //$module->emDebug('$json->filters=' . print_r($json->filters, true));

                //$module->emDebug('$all_fields[]=' . print_r($all_fields, true));
                //$module->emDebug('$formSql='.$formSql);
            }

            // date proximity is a very special case - this is the first try - not sure about the performace yet
            // Test with realistic data set and change if needed.
            if ($form->join_type == "date_proximity") {

                $upperBoundSet = (isset($form->upper_bound) && strlen(trim($form->upper_bound)) > 0);
                $lowerBoundSet = (isset($form->lower_bound) && strlen(trim($form->lower_bound)) > 0);

                $dateValSel = "rd.value";  // In date proximity join case, the value is always rd.value

                $formSql = "Select " . $form->form_name . "_int.*, " . $form->form_name . "_dproxy." . $form->foreign_key_ref . "_instance " .
                    "From " .
                    $formSql . " FROM redcap_data rd, redcap_metadata rm " .
                    "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
                    "GROUP BY rd.record, rd.instance ) " . $form->form_name . "_int, " .
                    "  (select m.record, m.instance " . $form->form_name . "_instance, " .
                    "    (select COALESCE (rd.instance, 1) " .
                    "	from redcap_data rd, redcap_metadata rm " .
                    "	where rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.record = m.record and rd.field_name = '" . $form->foreign_key_field . "' and rd.project_id  = " . $project_id . " " .
                    ($lowerBoundSet ? ("   and datediff($dateValSel, m." . $form->primary_date_field . ") <= " . $form->lower_bound . " ") : " ") .
                    ($upperBoundSet ? ("   and datediff(m." . $form->primary_date_field . ", $dateValSel) <= " . $form->upper_bound . " ") : " ") .
                    "	order by abs(datediff(m." . $form->primary_date_field . ", $dateValSel)) asc " .
                    "	limit 1 " .
                    ") as " . $form->foreign_key_ref . "_instance " .
                    "from ( select distinct rd.record, COALESCE(rd.instance, 1) as instance, $dateValSel as " . $form->primary_date_field . " " .
                    "	  from redcap_data rd, redcap_metadata rm where rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id  = " . $project_id . " and rd.field_name  = '" . $form->primary_date_field . "'  " .
                    "	) m " .
                    ") " . $form->form_name . "_dproxy " .
                    "where " . $form->form_name . "_int.instance = " . $form->form_name . "_dproxy." . $form->form_name . "_instance and " .
                    $form->form_name . "_int.record = " . $form->form_name . "_dproxy.record ";

                $formSql = $formSql . ") " . $form->form_name;

                if ($primaryForm) {
                    $from = "FROM " . $formSql;
                } else {
                    $from = $from . " left outer join ( " . $formSql . " ON ( " . $form->form_name . ".record = " . $primaryFormName . ".record  " .
                        "and " . $form->form_name . "." . $form->foreign_key_ref . "_instance = " . $form->foreign_key_ref . ".instance )";
                }

                //$module->emDebug("SQL inside the date_proximity : " . $from) ;

            } else {

                // Singletons - group by the record only
                if ($form->cardinality == "singleton") {
                    $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " .
                        "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
                        "GROUP BY rd.record";
                } else {   // for repeating forms group by record and instance
                    $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " .
                        "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
                        "GROUP BY rd.record, rd.instance";
                }

                $formSql = $formSql . ") " . $form->form_name;

                if ($primaryForm) {
                    $from = "FROM " . $formSql;
                } else {
                    $from = $from . " left outer join " . $formSql . " ON ( " . $form->form_name . ".record = " . $primaryFormName . ".record  ";

                    // If it is instance join type, join with "instance" column of the parent
                    if (isset($form->join_type)) {
                        if ($form->join_type == "instance") {
                            $form->join_condition = $form->foreign_key_field . " = " . $form->foreign_key_ref . ".instance";
                        } elseif ($form->join_type == "lookup") {  // Not implemented yet. To support joining any columns
                            $form->join_condition = $form->join_key_field . " = " . $form->foreign_key_ref . "." . $form->foreign_key_field;
                        }
                    }

                    if (isset($form->join_condition)) {
                        $from = $from . " and " . $form->join_condition;
                    }

                    $from = $from . " ) ";
                }
            }
        }

        // If record_id is not chosen add it to the SQL
        if ($recordFieldIncluded)
            $sql = "Select " . $select . " " . $from;
        else {
            $sql = "Select " . $primaryFormName . ".record as " . $json->record_id . ", " . $select . " " . $from;
            array_unshift($select_fields, $json->record_id);   // add record_id as the first field in the fields array
        }

        if (isset($json->filters)) {
            $filtersql = $this->processFilters($json->filters, $all_fields, $valSel);
            if (strlen($filtersql) > 0) {
                $sql = $sql . " where " . $filtersql;
            }
        }

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
                $data = [];
                if ("false" == $json->preview) {
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
            }
        } else {
            $result["status"] = 0;
            $result["message"] = "No data requested. You must specify at least one column";
        }

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
        $col = $filter->instrument . "." . $filter->field;
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
    function processFilters($filters, $all_fields, $valSel)
    {

        global $module;

        $module->emDebug('all fields :' . print_r($all_fields, TRUE));

        $filtersql = "";

        foreach ($filters as $filter) {

            $filterstr = $this->filter_string($filter);

            if (!in_array($filter->field, $all_fields)) {

                $filterstr = str_replace($filter->instrument . "." . $filter->field, $valSel, $filterstr);

                $filterstr = " exists (select 1 from redcap_data rd, redcap_metadata rm " .
                    "    where rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id  = " . $this->Proj->project_id . " and " .
                    "         rd.field_name = '" . $filter->field . "' and " . $filterstr .
                    "   and rd.record  = person.record ) ";

                $filtersql = $filtersql . $filterstr . " " . $filter->boolean . " ";
            } else {
                $filtersql = $filtersql . $filterstr . " " . $filter->boolean . " ";
            }
        }

        if (substr($filtersql, -4) == "AND " || substr($filtersql, -4) == "OR ")
            $filtersql = substr($filtersql, 0, strlen($filtersql) - 4);

        return $filtersql;

    }

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
        return $this->useTempFile;
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


}

/*current : (
[0] => Array
        ( [0] => 1
            [1] => 2020-07-09
            [2] => One         )
[1] => Array
        (  [0] => 3
            [1] => 2020-10-07
            [2] => One \nTwo   ) )
target : (
[0] => Array
        ( [0] => 1
            [1] => 2020-07-09
            [2] => One
            [3] =>
            [4] =>          )
[1] => Array
        (  [0] => 3
            [1] => 2020-10-07
            [2] => One
            [3] => Two
            [4] =>          )
*/
