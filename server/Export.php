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
        $json->preview = $config['preview'];
        foreach ($json_inp->columns as $column) {

            if (!isset($json->forms[$column->instrument])) {
                $json->forms[$column->instrument] = json_decode('{ "fields" : [] }');
                $json->forms[$column->instrument]->form_name = $column->instrument;
                $meta = $this->instrumentMetadata->isRepeating($column->instrument);
                $json->forms[$column->instrument]->cardinality = $meta['cardinality'];
                if ( isset($meta['foreign_key_ref']) && strlen($meta['foreign_key_ref']) > 0)  {
                    $json->forms[$column->instrument]->join_condition =
                        $column->instrument . '.' . $meta['foreign_key_field'] . ' = ' .
                        $meta['foreign_key_ref'] . '.instance';
                }
            }
            $json->forms[$column->instrument]->fields[] = $column->field;
        }
        // TODO if record_id is missing, add it. Every report needs the record_id

        $module->emDebug('final json '.print_r($json,true));
        return $json;
    }


    /*
     * use the transformed specification to build and execute the SQL
     */
    function runQuery($json)
    {
        global $module;

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

            error_log("Processing form " . $form->form_name);

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

        // preview row limit
        if ("true" == $json->preview && strlen(trim($sql)) > 0) {
            $sql = $sql . " LIMIT 200";
        }

        $module->emDebug($sql);
        error_log( "SQL to execute :" . $sql);

        if ( strlen(trim($sql)) > 0) {
            $rptdata = db_query($sql);

            error_log('db_numrows ' . db_num_rows($rptdata));
            $result["status"] = 1; // when status = 0 the client will display the error message
            if (strlen(db_error()) > 0) {
                $dberr =  db_error();
                error_log($dberr);
                $result["status"] = 0;
                $result["message"] = $dberr;
            } else {
                $data = [];
                if ("false" == $json->preview) {
                   $data[] = $headers;
                }
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
        } else {
            $result["status"] = 0;
            $result["message"] = "No data requested. You must specify at least one column";
        }
        error_log('result ' . print_r($result, TRUE));

        return $result;
    }
}
