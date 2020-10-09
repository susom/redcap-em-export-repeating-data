<?php

namespace Stanford\ExportRepeatingData;
/** @var ExportRepeatingData $module */

/**
 * Class Export
 * @package Stanford\ExportRepeatingData
 *
 */
class Export
{

    private $Proj;
    private $instrumentMetadata;

    function __construct($project, $instrumentMetadata)
    {
        $this->Proj = $project;
        $this->instrumentMetadata = $instrumentMetadata;
    }

    function buildAndRunQuery($config)
    {
        $newConfig = $this->assembleSpecification($config);
        $result = $this->runQuery($newConfig);
        return $result;
    }

    function assembleSpecification($config)
    {
        global $module;

        // oddly enough this operation is not idempotent. the incoming arrays get converted into objects
        $json_inp = json_decode(json_encode($config));
        $json = json_decode('{ "forms" : []}');
        $json->raw_or_label = $json_inp->raw_or_label;
        // look through the incoming spec for the primary-repeating form, since that will be the join key
        // used by any repeating-date-pivot references. There can be only one...
        foreach($json_inp->cardinality as $instrument => $value) {
            if ($value->join === 'repeating-primary') {
                $primaryJoinInstrument = $instrument;
                $meta = $this->instrumentMetadata->isRepeating($instrument);
                $primaryJoinField = $meta['principal_date'];
                break;
            }
        }
//        $module->emDebug('$json_inp is '.print_r($json_inp,TRUE));
        //  if record_id is missing, add it. Every report needs the record_id
        // look up name of record_id
        foreach ($this->Proj->metadata as $identifier_field => $firstRecord) {
            break;
            // $identifier_field is now correctly set. it is referenced below
        }
        // $record_identifier now contains 'record_id' or whatever the first field is named
        // now look for it in the first pass
        $found_record_identifier = false;

        // stash the preview setting, the SQL generation step needs to know
        $json->preview = $json_inp->preview ;  // $config['preview'];
        foreach ($json_inp->columns as $column) {
            $instrument = $column->instrument;
            if (!isset($json->forms[$instrument])) {
                $json->forms[$instrument] = json_decode('{ "fields" : [] }');
                $json->forms[$instrument]->form_name = $column->instrument;
                                
                $meta = $this->instrumentMetadata->isRepeating($column->instrument);
                $json->forms[$instrument]->cardinality = $meta['cardinality'];
                $joinType = $json_inp->cardinality->$instrument->join;
                if ( $joinType == 'repeating-instance-select')  {
                    $json->forms[$instrument]->join_type = 'instance';
                    $json->forms[$instrument]->foreign_key_ref = $meta['foreign_key_ref'] ;
                    $json->forms[$instrument]->foreign_key_field = $meta['foreign_key_field'] ;
                } else if (  $joinType == 'repeating-date-pivot') {
                    $json->forms[$instrument]->join_type = 'date_proximity';
                    $json->forms[$instrument]->foreign_key_ref = $primaryJoinInstrument ;
                    $json->forms[$instrument]->foreign_key_field = $primaryJoinField ;
                    $json->forms[$instrument]->lower_bound = $json_inp->cardinality->$instrument->lower_bound;
                    $json->forms[$instrument]->upper_bound = $json_inp->cardinality->$instrument->upper_bound;
                    $json->forms[$instrument]->primary_date_field = $json_inp->cardinality->$instrument->primary_date;

                }
            }
            $json->forms[$column->instrument]->fields[] = $column->field;
            $found_record_identifier = $found_record_identifier || ($column->field === $identifier_field);
            if (! $found_record_identifier) {
                $json->record_id = $identifier_field;
            }
        }

        $json->filters = $json_inp->filters ;
        
        $module->emDebug('final json '.print_r($json,true));
        return $json;
    }

    function array_unshift_assoc(&$arr, $key, $val)
    {
        $arr = array_reverse($arr, true);
        $arr[$key] = $val;
        $arr = array_reverse($arr, true);
        return $arr;
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
        if (! isset($rowLimit)) {
            $rowLimit = 200;
        }
        if ($json->raw_or_label == "label") {
            //  added length(rd.value) + 2 to remove the "n, " in "n, label" format
            $valSel = "coalesce(SUBSTRING_INDEX(substring(element_enum, instr(element_enum, concat(rd.value, ',')) + length(rd.value) + 2), '\\\\n',1), rd.value)";
        } else {
            $valSel = "rd.value";
        }
        $project_id = $this->Proj->project_id;
        $select = "" ;
        $from = "" ;
        $fields = array() ;
        $recordFieldIncluded = ! isset($json->record_id) ;
        $module->emDebug('is record_id included? ' . $recordFieldIncluded . ' ' . $json->record_id);
        $primaryFormName = "" ;
        
        foreach ($json->forms as $form) {

            // To identify the key instrument - first instrument is considered primary and all other instruments will 
            // be joined as left outer joins

            $primaryForm = ($from == "") ;
            if ($primaryForm) {
                $primaryFormName = $form->form_name ;
            }
        
            // mapping null instance to '1'
            $formSql = (($form->cardinality == 'singleton') ? " ( select rd.record " : " ( select rd.record, COALESCE(rd.instance, '1') instance ") ;
        
            // Converting redcap_data into a view format for each selected fields
            foreach($form->fields as $field) {
                $fields[] = $field ;
                $formSql = $formSql . ", max(case when rd.field_name = '" . $field . "' then $valSel end) " . $field . " ";
            }
            
            // date proximity is a very special case - this is the first try - not sure about the performace yet
            // Test with realistic data set and change if needed.
            if ($form->join_type == "date_proximity") {

                $upperBoundSet = (isset($form->upper_bound) && strlen(trim($form->upper_bound)) > 0) ;
                $lowerBoundSet = (isset($form->lower_bound) && strlen(trim($form->lower_bound)) > 0) ;
                
                $dateValSel = "rd.value" ;  // In date proximity join case, the value is always rd.value
                
                $formSql =  "Select " . $form->form_name . "_int.*, " . $form->form_name . "_dproxy." . $form->foreign_key_ref . "_instance " . 
                        "From " .
                        $formSql . " FROM redcap_data rd, redcap_metadata rm " . 
                        "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " . 
                        "GROUP BY rd.record, rd.instance ) " . $form->form_name . "_int, " .
                        "  (select m.record, m.instance ". $form->form_name . "_instance, " .
                        "    (select COALESCE (rd.instance, 1) " .
                        "	from redcap_data rd, redcap_metadata rm " .
                        "	where rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.record = m.record and rd.field_name = '" . $form->foreign_key_field . "' and rd.project_id  = " . $project_id . " " .
                        ($lowerBoundSet?("   and datediff($dateValSel, m." . $form->primary_date_field . ") <= " . $form->lower_bound . " "): " ") .
                        ($upperBoundSet?("   and datediff(m." . $form->primary_date_field . ", $dateValSel) <= " . $form->upper_bound . " "):" ") .
                        "	order by abs(datediff(m." . $form->primary_date_field . ", $dateValSel)) asc " .
                        "	limit 1 " . 
                        ") as " . $form->foreign_key_ref . "_instance " .
                        "from ( select distinct rd.record, COALESCE(rd.instance, 1) as instance, $dateValSel as " . $form->primary_date_field . " " .
                            "	  from redcap_data rd, redcap_metadata rm where rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id  = " . $project_id . " and rd.field_name  = '" . $form->primary_date_field . "'  " .
                            "	) m " . 
                        ") " . $form->form_name . "_dproxy " .
                        "where " . $form->form_name . "_int.instance = " . $form->form_name . "_dproxy." . $form->form_name . "_instance and " . 
                        $form->form_name . "_int.record = " . $form->form_name . "_dproxy.record "  ;

                $formSql = $formSql . ") " . $form->form_name ;

                if ($primaryForm) {
                    $from = "FROM " . $formSql ;
                } else {
                    $from = $from . " left outer join ( " . $formSql . " ON ( " . $form->form_name . ".record = " . $primaryFormName . ".record  " .
                                            "and " . $form->form_name . "." . $form->foreign_key_ref . "_instance = " . $form->foreign_key_ref . ".instance )" ;
                }
                
                //$module->emDebug("SQL inside the date_proximity : " . $from) ;
                
            } else {
        
                // Singletons - group by the record only
                if ($form->cardinality == "singleton") {
                    $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " . 
                            "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " . 
                            "GROUP BY rd.record" ;
                } else {   // for repeating forms group by record and instance
                    $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " . 
                            "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " . 
                            "GROUP BY rd.record, rd.instance" ;        
                }
            
                $formSql = $formSql . ") " . $form->form_name ;
            
                if ($primaryForm) {
                    $from = "FROM " . $formSql ;
                } else {
                    $from = $from . " left outer join " . $formSql . " ON ( " . $form->form_name . ".record = " . $primaryFormName . ".record  " ;
                    
                    // If it is instance join type, join with "instance" column of the parent
                    if (isset($form->join_type)) {
                        if ($form->join_type == "instance") {
                            $form->join_condition = $form->foreign_key_field . " = " . $form->foreign_key_ref . ".instance" ;
                        } elseif ($form->join_type == "lookup") {  // Not implemented yet. To support joining any columns
                            $form->join_condition = $form->join_key_field . " = " . $form->foreign_key_ref . "." . $form->foreign_key_field ;
                        }    
                    }

                    if (isset($form->join_condition)) {
                        $from = $from . " and " . $form->join_condition ;
                    }
            
                    $from = $from . " ) " ;
                }
            }                            

            // Doing coalesce so nulls will be displayed as '' in output reports
            foreach($form->fields as $field) {
                $select = $select . ( ($select == "") ? " " : ", ") . "COALESCE(" . $field . ", '') " . $field ;
            }            
        }

        // If record_id is not chosen add it to the SQL
        if ($recordFieldIncluded)
            $sql = "Select " . $select . " " . $from ;
        else {
            $sql = "Select " . $primaryFormName . ".record as " . $json->record_id . ", " . $select . " " . $from ;
            array_unshift($fields, $json->record_id) ;   // add record_id as the first field in the fields array
        }
        
        if (isset($json->filters)) {
            $filtersql = $this->processFilters($json->filters) ;
            if (strlen($filtersql) > 0) {
                $sql = $sql . " where " . $filtersql ;
            }            
        }

        if ("true" == $json->preview && strlen(trim($sql)) > 0) {
            $sql = $sql . " LIMIT " . $rowLimit;
        }

        $module->emDebug($sql);

        if ( strlen(trim($sql)) > 0) {
            $rptdata = db_query($sql);

            $result["status"] = 1; // when status = 0 the client will display the error message
            if (strlen(db_error()) > 0) {
                $dberr =  db_error();
                error_log($dberr);
                $module->emlog($dberr);
                $result["status"] = 0;
                $result["message"] = $dberr;
            } else {
                $data = [];
                if ("false" == $json->preview) {
                   $data[] = $fields ;  // $headers;
                }
                while ($row = db_fetch_assoc($rptdata)) {
                    $cells = [];
                    for ($k = 0 ; $k < count($fields); $k++) {
                        $cells[] = $row[$fields[$k]];
                    }
                    $data[] = $cells;
                }
                $result["headers"] = $fields;
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
    function processFilters($filters) {

        $filtersql = "" ;

        foreach ($filters as $filter) {
            
            /* To test 
            if ($filter->field == "pft_test_date") 
                $filter->validation = "date_mdy" ;
            if ($filter->field == "no_of_tests") 
                $filter->validation = "integer" ;
            */

            $col = $filter->instrument . "." . $filter->field ;            
            $val = db_escape($filter->param) ;
            $dt = "string" ;

            if ($this->endsWith($filter->validation, "_dmy")) {
                $col = "str_to_date(" . $col . ", '%Y-%m-%d')" ;
                $val = "str_to_date('" . $val . "', '%d-%m-%Y')" ;
                $dt = "date" ;
            } elseif ($this->endsWith($filter->validation, "_mdy")) {
                $col = "str_to_date(" . $col . ", '%Y-%m-%d')" ;
                $val = "str_to_date('" . $val . "', '%m-%d-%Y')" ;
                $dt = "date" ;
            } elseif ($this->endsWith($filter->validation, "_ymd")) {
                $col = "str_to_date(" . $col . ", '%Y-%m-%d')" ;
                $val = "str_to_date('" . $val . "', '%Y-%m-%d')" ;
                $dt = "date" ;
            }
            
            if (($filter->validation == "integer" || $filter->validation == "number"))
                $dt = "number" ;
            
            if ($filter->operator == "E")
                $filterstr = ($dt == "string") ? ($col . " = '" . $val . "'") : ($col . " = " . $val) ;
            elseif ($filter->operator == "NE")
                $filterstr = ($dt == "string") ? ($col . " <> '" . $val . "'") : ($col . " <> " . $val) ;
            elseif ($filter->operator == "CONTAINS")
                $filterstr = $col . " like '%" . $val . "%'";
            elseif ($filter->operator == "NOT_CONTAIN")
                $filterstr = $col . " not like '%" . $val . "%'";
            elseif ($filter->operator == "STARTS_WITH")
                $filterstr = $col . " like '" . $val . "%'";
            elseif ($filter->operator == "ENDS_WITH")
                $filterstr = $col . " like '%" . $val . "'";
            elseif ($filter->operator == "LT")
                $filterstr = ($dt == "string") ? ($col . " < '" . $val . "'") : ($col . " < " . $val) ;                
            elseif ($filter->operator == "LTE")
                $filterstr = ($dt == "string") ? ($col . " <= '" . $val . "'") : ($col . " <= " . $val) ;
            elseif ($filter->operator == "GT")
                $filterstr = ($dt == "string") ? ($col . " > '" . $val . "'") : ($col . " > " . $val) ;                
            elseif ($filter->operator == "GTE")
                $filterstr = ($dt == "string") ? ($col . " >= '" . $val . "'") : ($col . " >= " . $val) ;
                

            $filtersql = $filtersql . $filterstr . " " . $filter->boolean . " ";
        }

        if (substr($filtersql, -4) == "AND " || substr($filtersql, -4) == "OR ")
            $filtersql = substr($filtersql, 0, strlen($filtersql) - 4) ;

        return $filtersql ;

    }
    
}

