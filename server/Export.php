<?php

namespace Stanford\ExportRepeatingData;
/** @var ExportRepeatingData $module */
use \REDCap;
use \Project;

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
        $newConfig = $this->assembleCardinality($config);
        $result = $this->runQuery($newConfig);
        return $result;
    }

    function assembleCardinality($config)
    {
        global $module;

        // oddly enough this operation is not idempotent. the incoming arrays get converted into objects
        $json_inp = json_decode(json_encode($config));
        $json = json_decode('{ "forms" : []}');

        //  if record_id is missing, add it. Every report needs the record_id
        // look up name of record_id
        foreach ($this->Proj->metadata as $identifier_field => $firstRecord) {
            $identifier_form = $firstRecord['form_name'];
            break;
        }
        // $record_identifier now contains 'record_id' or whatever the first field is named
        // now look for it in the first pass
        $found_record_identifier = false;

        // stash the preview setting, the SQL generation step needs to know
        $json->preview = $json_inp->preview ;  // $config['preview'];

        foreach ($json_inp->columns as $column) {

            if (!isset($json->forms[$column->instrument])) {
                $json->forms[$column->instrument] = json_decode('{ "fields" : [] }');
                $json->forms[$column->instrument]->form_name = $column->instrument;
                                
                $meta = $this->instrumentMetadata->isRepeating($column->instrument);
                $json->forms[$column->instrument]->cardinality = json_decode('{}') ;
                $json->forms[$column->instrument]->cardinality->cardinality = $meta['cardinality'];
                if ( isset($meta['foreign_key_ref']) && strlen($meta['foreign_key_ref']) > 0)  {
                    $json->forms[$column->instrument]->cardinality->join_type = 'instance' ;
                    $json->forms[$column->instrument]->cardinality->foreign_key_ref = $meta['foreign_key_ref'] ;
                    $json->forms[$column->instrument]->cardinality->foreign_key_field = $meta['foreign_key_field'] ;
                }
            }
            $json->forms[$column->instrument]->fields[] = $column->field;
            $found_record_identifier = $found_record_identifier || ($column->field === $identifier_field);
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
    */
    function runQuery($json)
    {
        global $module;

        $project_id = $this->Proj->project_id;
        $select = "" ;
        $from = "" ;
        $fields = array() ;
        $recordFieldIncluded = false ;
        
        $primaryForm = false ;
        $primaryFormName = "" ;
        
        foreach ($json->forms as $form) {
        
            $primaryForm = ($from == "") ;
            if ($primaryForm) {
                $primaryFormName = $form->form_name ;
            }
        
            if (!$recordFieldIncluded)
                $recordFieldIncluded = in_array("record_id", $form->fields) ;
        
            // echo "Processing form " . $form->form_name . "<br>" ;
        
            $formSql = (($form->cardinality->cardinality == 'singleton') ? " ( select rd.record " : " ( select rd.record, COALESCE(rd.instance, '1') instance ") ;
        
            foreach($form->fields as $field) {
                $fields[] = $field ;
                $formSql = $formSql . ", max(case when rd.field_name = '" . $field . "' then rd.value end) " . $field . " ";
            }
                        
            if (isset($form->cardinality))
            {
                $cardinality = $form->cardinality ;

                if (isset($cardinality->join_type) && $cardinality->join_type == "date_proximity") {
                    $formsql =  "Select " . $form->form_name . "_int.*, " . $form->form_name . "_dproxy." . $cardinality->foreign_key_ref . "_instance" . 
                            "From " .
                            $formSql . " FROM redcap_data rd, redcap_metadata rm " . 
                            "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " . 
                            "GROUP BY rd.record, rd.instance ) " . $form->form_name . "_int, " .
                            "  (select m.record, m.instance ". $form->form_name . "_instance, " .
                            "    (select COALESCE (rd.instance, 1) " .
                            "	from redcap_data rd " .
                            "	where rd.record = m.record and rd.field_name = '" . $cardinality->foreign_key_field . "' and rd.project_id  = " . $project_name . " " .
                            "	order by abs(datediff(m." . $cardinality->join_field . ", rd.value)) asc " . 
                            "	limit 1 " . 
                            ") as " . $cardinality->foreign_key_ref . "_instance " .
                            "from ( select distinct rd.record, COALESCE(rd.instance, 1) as instance, rd.value as " . $cardinality->join_field . " " .
                               "	  from redcap_data rd where rd.project_id  = " . $project_id . " and rd.field_name  = '" . $cardinality->join_field . "'  " .
                               "	) m " . 
                            ") " . $form->form_name . "._dproxy " .
                            "where " . $form->form_name . "_int.instance = " . $form->form_name . "_dproxy." . $form->form_name . "_instance and " . 
                            $form->form_name . "_int.record = " . $form->form_name . "_dproxy.record "  ;
                            ") " . $form.form_name . " " .
                            "ON (" . $primaryFormName  . ".record = " . $form->form_name . ".record and " . 
                            $form->form_name . "." . $cardinality->foreign_key_ref . "_instance = " . $cardinality->foreign_key_ref . ".instance ) " ;

                    $formSql = $formSql . ") " . $form->form_name ;

                    if ($primaryForm) {
                        $from = "FROM " . $formSql ;
                    } else {
                        $from = $from . " left outer join " . $formSql . " " ;                        
                    }
            
                } else {
            
                    if ($cardinality->cardinality == "singleton") {
                        $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " . 
                                "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " . 
                                "GROUP BY rd.record" ;
                    } else {
                        $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " . 
                                "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " . 
                                "GROUP BY rd.record, rd.instance" ;        
                    }
                
                    $formSql = $formSql . ") " . $form->form_name ;
                
                    if ($primaryForm) {
                        $from = "FROM " . $formSql ;
                    } else {
                        $from = $from . " left outer join " . $formSql . " ON ( " . $form->form_name . ".record = " . $primaryFormName . ".record  " ;
                        
                        if (isset($form->cardinality->join_type)) {
                            if ($form->cardinality->join_type == "instance") { 
                                $form->join_condition = $cardinality->foreign_key_field . " = " . $cardinality->foreign_key_ref . ".instance" ;
                            } elseif ($form->cardinality->join_type == "foreign_key") { 
                                $form->join_condition = $cardinality->join_field . " = " . $cardinality->foreign_key_ref . "." . $cardinality->foreign_key_field ;
                            }    
                        }

                        if (isset($form->join_condition)) {
                            $from = $from . " and " . $form->join_condition ;
                        }
                
                        $from = $from . " ) " ;
                    }
                }                
            }

            // Doing coalesce so nulls will be displayed as '' in output reports
            foreach($form->fields as $field) {
                $select = $select . ( ($select == "") ? " " : ", ") . "COALESCE(" . $field . ", '') " . $field ;
            }            
        }

        // IF record_id is not chosen add it to the SQL 
        if ($recordFieldIncluded)
            $sql = "Select " . $select . " " . $from ;
        else {
            $sql = "Select record as record_id, " . $select . " " . $from ;
            array_unshift($fields, "record_id") ;   // add record_id as the first field in the fields array
        }
        
        
        if (isset($json->filters)) {
            $filtersql = $this->processFilters($json->filters) ;
            if (strlen($filtersql) > 0) {
                $sql = $sql . " where " . $filtersql ;
            }
            //$sql = $sql . " where " . implode(" and ", $json->filters);
        }

        if ("true" == $json->preview && strlen(trim($sql)) > 0) {
            $sql = $sql . " LIMIT 200";
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

    // Just the raw format values. v1 - needs iteration
    function processFilters($filters) {

        $filtersql = "" ;

        foreach ($filters as $filter) {

            $filstr = $filter->instrument . "." . $filter->field . " " ;
            
            if ($filter->operator == "E")
                $filstr = $filstr . " = '" . db_escape($filter->param) . "'" ;
            elseif ($filter->operator == "NE")
                $filstr = $filstr . " <> '" . db_escape($filter->param) . "'" ;
            elseif ($filter->operator == "CONTAINS")
                $filstr = $filstr . " like '%" . db_escape($filter->param) . "%'" ;
            elseif ($filter->operator == "NOT_CONTAIN")
                $filstr = $filstr . " not like '%" . db_escape($filter->param) . "%'" ;        
            elseif ($filter->operator == "STARTS_WITH")
                $filstr = $filstr . " like '" . db_escape($filter->param) . "%'" ;                
            elseif ($filter->operator == "ENDS_WITH")
                $filstr = $filstr . " like '%" . db_escape($filter->param) . "'" ;                        
            elseif ($filter->operator == "LT")
                $filstr = $filstr . " < '" . db_escape($filter->param) . "'" ;        
            elseif ($filter->operator == "LTE")
                $filstr = $filstr . " <= '" . db_escape($filter->param) . "'" ;        
            elseif ($filter->operator == "GT")
                $filstr = $filstr . " > '" . db_escape($filter->param) . "'" ;        
            elseif ($filter->operator == "GTE")
                $filstr = $filstr . " >= '" . db_escape($filter->param) . "'" ;     

            $filtersql = $filtersql . $filstr . " " . $filter->boolean . " ";
        }

        if (substr($filtersql, -4) == "AND " || substr($filtersql, -4) == "OR ")
            $filtersql = substr($filtersql, 0, strlen($filtersql) - 4) ;

        return $filtersql ;

    }
    
}


            /*
            {
                "instrument": "pft",
                "cardinality": "repeating-secondary ",
                "join_type": "date_proximity",
                "join_field": "pft_test_date",
                "foreign_key_field": "visit_date",
                "foreign_key_ref": "visit"
        
                "instrument": "meds",
                "cardinality": "repeating-secondary ",
                "join_type": "date_proximity",
                "join_field": "med_visit_date",
                "foreign_key_field": "visit_date",
                "foreign_key_ref": "visit"        
            }
        
            left outer JOIN 
             (
                select meds_int.*, meds_dproxy.visit_instance 
                from  	
                    (SELECT rd.record, COALESCE(rd.`instance`, 1) instance, max(case when rd.field_name = 'med_visit_date' then rd.value end) med_visit_date, max(case when rd.field_name = 'medication' then rd.value end) medication, max(case when rd.field_name = 'meds_complete' then rd.value end) meds_complete 
                      FROM redcap_data rd, redcap_metadata rm
                      WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = 15 and rm.form_name = "meds" 
                      GROUP BY rd.record, COALESCE(rd.`instance`, 1)) meds_int, 
                    (select m.record, m.instance med_instance, 
                        (select COALESCE (rd.instance, 1)
                            from redcap_data rd
                            where rd.record = m.record and rd.field_name = 'visit_date' and rd.project_id  = 15
                            order by abs(datediff(m.med_visit_date, rd.value)) asc
                            limit 1
                        ) as visit_instance
                      from ( select distinct rd.record, COALESCE(rd.instance, 1) as instance, rd.value as med_visit_date
                                 from redcap_data rd where rd.project_id  = 15 and rd.field_name  = 'med_visit_date' 
                               ) m
                    ) meds_dproxy			
                where meds_int.instance = meds_dproxy.med_instance and meds_int.record = meds_dproxy.record
             ) meds
            ON (pat.record = meds.record and meds.visit_instance = visit.instance)	 
        
            */
