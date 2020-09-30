<?php

namespace Stanford\ExportRepeatingData;

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
        error_log($config);
        $newConfig = $this->assembleCardinality($config);
        $result = $this->runQuery($newConfig);
        return $result;
    }

    function assembleCardinality($config)
    {
        // oddly enough this operation is not idempotent. the incoming arrays get converted into objects
        $json_inp = json_decode(json_encode($config));
        $json = json_decode('{ "forms" : []}');

        foreach ($json_inp->columns as $column) {
            error_log('loop '.print_r($column, TRUE));
            if (!isset($json->forms[$column->instrument])) {
                $json->forms[$column->instrument] = json_decode('{ "fields" : [] }');
                $json->forms[$column->instrument]->form_name = $column->instrument;
                $meta = $this->instrumentMetadata->isRepeating($column->instrument);
                error_log(print_r($meta,TRUE));
                $json->forms[$column->instrument]->cardinality = $meta['cardinality'];
                if ( isset($meta['foreign_key_ref']) && strlen($meta['foreign_key_ref']) > 0)  {
                    $json->forms[$column->instrument]->join_condition =
                        $column->instrument . '.' . $meta['foreign_key_field'] . ' = ' .
                        $meta['foreign_key_ref'] . '.instance';
                }
            }
            $json->forms[$column->instrument]->fields[] = $column->field;
        }
        error_log('final json '.print_r($json,true));
        // TODO if record_id is missing, add it. Every report needs the record_id
        return $json;
    }


    /*
     * use the transformed specification to build and execute the SQL
     */
    function runQuery($json) {

        $project_id = $this->Proj->project_id;
        $select = "";
        $from = "";
        $fields = array();

        $primaryForm = false;
        $primaryFormName = "";
        $headers = [];

        foreach ($json->forms as $form) {

            // if ($primaryForm) = ($json->primaryFormName == $form->form_name) ;
            $primaryForm = ($from == "");
            if ($primaryForm) {
                $primaryFormName = $form->form_name;
            }

            error_log( "Processing form " . $form->form_name );

            $formSql = ($primaryForm ? " ( select rd.record " : " ( select rd.record, rd.instance ");

            foreach ($form->fields as $field) {
                $fields[] = $field;
                $headers[] = $field;
                $formSql = $formSql . ", max(case when rd.field_name = '" . $field . "' then rd.value end) " . $field . " ";
            }

            if ($form->cardinality == "singleton") {
                $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " .
                    "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
                    "GROUP BY rd.record";
            } else {
                $formSql = $formSql . " FROM redcap_data rd, redcap_metadata rm " .
                    "WHERE rd.project_id  = rm.project_id and rm.field_name  = rd.field_name and rd.project_id = " . $project_id . " and rm.form_name = '" . $form->form_name . "' " .
                    "GROUP BY rd.record, rd.instance";
            }

            $formSql = $formSql . ") " . $form->form_name;

            if ($primaryForm) {
                $from = "FROM " . $formSql;
            } else {
                $from = $from . " left outer join " . $formSql . " ON ( " . $form->form_name . ".record = " . $primaryFormName . ".record  ";

                if (isset($form->join_condition)) {
                    $from = $from . " and " . $form->join_condition;
                }

                $from = $from . " ) ";
            }

            if ($select == "") {
                $select = "Select " . implode(",", $form->fields);
            } else {
                $select = $select . ", " . implode(",", $form->fields);
            }

        }

        $sql = $select . " " . $from;

        if (isset($json->filter)) {
            $sql = $sql . " where " . implode(" and ", $json->filter);
        }

        error_log( "SQL to execute :" . $sql);

        $rptdata = db_query($sql);

        $result["status"] = 1; // when status = 0 the client will display the error message
        if (strlen(db_error()) > 0) {
            $dberr =  db_error();
            error_log($dberr);
            $result["status"] = 0;
            $result["message"] = $dberr;
        } else {
            $data = [];
            while ($row = db_fetch_assoc($rptdata)) {
                $cells = [];
                for ($k = 0 ; $k < count($headers); $k++) {
                    $cells[] = $row[$headers[$k]];
                }
                $data[] = $cells;
            }
            $result["headers"] = $headers;
            $result["data"] = $data;
        }

        return $result;
    }
}