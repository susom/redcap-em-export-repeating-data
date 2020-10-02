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
        $json->preview = $config['preview'];

        foreach ($json_inp->columns as $column) {

            if (!isset($json->forms[$column->instrument])) {
                $json->forms[$column->instrument] = json_decode('{ "fields" : [] }');
                $json->forms[$column->instrument]->form_name = $column->instrument;
                $meta = $this->instrumentMetadata->isRepeating($column->instrument);
                $json->forms[$column->instrument]->cardinality = $meta['cardinality']; // this may be overridden later
                if ( isset($meta['foreign_key_ref']) && strlen($meta['foreign_key_ref']) > 0)  {
                    $json->forms[$column->instrument]->join_condition =
                        $column->instrument . '.' . trim($meta['foreign_key_field']) . ' = ' .
                        trim($meta['foreign_key_ref']) . '.instance';
                }
            }
            $json->forms[$column->instrument]->fields[] = $column->field;
            $found_record_identifier = $found_record_identifier || ($column->field === $identifier_field);
        }

        if (! $found_record_identifier) {
            $json->identifierField = $identifier_field;
            foreach ($json->forms as $instrument_name => $form) {
                array_unshift($form->fields, $instrument_name.'.record as '. $identifier_field);
                break;
            }

        }

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

            $module->emDebug("Processing form " . $form->form_name);

            $formSql = ($primaryForm ? " ( select rd.record " : " ( select rd.record, rd.instance ");

            foreach ($form->fields as $field) {
                $fields[] = $field;
                $module->emDebug('substr 10 is '.substr( $field, 0, 10 ));
                if (!strpos( $field, ".record as " ) ) {
                    $formSql = $formSql . ", max(case when rd.field_name = '" . $field . "' then rd.value end) " . $field . " ";
                    $headers[] = $field;
                } else {
                    $headers[] = $json->identifierField;
                }
            }

            if ($form->cardinality == "singleton" ) {
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

        return $result;
    }
}
