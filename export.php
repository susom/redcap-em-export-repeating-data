<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
# Render Table Page
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';


/*
echo "<h4> Total rows for this project :".$q1."</h4>" ;

$sql2 = "select gen_rpt_sql(" . db_escape($project_id) . ", 'meds', false)" ;
$gensql = db_result(db_query($sql2), "");

echo "<div> SQL for meds form :".$gensql."</div>" ;

$rptdata = db_query($gensql) ;

$markup = "<table border='1'><tr><th>Visit Date</th><th>Medication</th></tr>" ;
while ($row = db_fetch_assoc($rptdata)) {
    
    $markup = $markup."<tr><td>" . $row['med_visit_date'] . "</td><td>" . $row['medication'] . "</td></tr>";
}
$markup = $markup . "</table>" ;

echo $markup ;
*/

/*
"filter" : [ "last_name like \'S%\'" ]
*/

$json_str = '
    {
        "project": "standard",
        "preview": "true",
        "columns": [{
                "instrument": "person",
                "field": "last_name",
                "is_date": false
            },
            {
                "instrument": "person",
                "field": "first_name",
                "is_date": false
            },
            {
                "instrument": "visit",
                "field": "visit_date",
                "is_date": true
            },
            {
                "instrument": "visit",
                "field": "visit_type",
                "is_date": false
            },            
            {
                "instrument": "meds",
                "field": "medication",
                "is_date": false
            }            
        ],
        "cardinality": [{
                "instrument": "person",
                "cardinality": "singleton"
            },
            {
                "instrument": "visit",
                "cardinality": "repeating",
                "foreign_key_field": "",
                "foreign_key_ref": ""
            },
            {
                "instrument": "meds",
                "cardinality": "repeating",                
                "join_field": "med_visit_date",
                "foreign_key_field": "visit_date",
                "foreign_key_ref": "visit"
            }
        ],
        "filters": [{
                "instrument": "person",
                "field": "record_id",
                "operator": "equals",
                "param1": "1",
                "param2": ""
            }
        ]
    }
' ;


$json_inp = json_decode($json_str) ;
$json = json_decode('{ "forms" : []}') ;
echo "Json error1 :" . json_last_error() ;

foreach ($json_inp->columns as $column) {
    if (!isset($json->forms[$column->instrument])) {
        $json->forms[$column->instrument] = json_decode('{ "fields" : [] }') ; 
        $json->forms[$column->instrument]->form_name = $column->instrument ;
    }
    $json->forms[$column->instrument]->fields[] = $column->field ;
}

foreach ($json_inp->cardinality as $cardinality) {
    
    $form = $json->forms[$cardinality->instrument] ;

    if ($cardinality->cardinality == "singleton") {
        $form->cardinality = "singleton" ;
    } else {
        $form->cardinality = "repeating" ;
    }
    if (isset($cardinality->join_field)) {
        
        if (isset($cardinality->join_type) && $cardinality->join_type == "instance") {
            $form->join_condition = $cardinality->foreign_key_field . " = " . $cardinality->foreign_key_ref . ".instane" ;
        } else {
            $form->join_condition = $cardinality->join_field . " = " . $cardinality->foreign_key_ref . "." . $cardinality->foreign_key_field ;
        }
        
        if (!in_array($cardinality->join_field, $form->fields)) {
            $form->fields[] = $cardinality->join_field ;
        }
        
        if (!in_array($cardinality->foreign_key_field, $json->forms[$cardinality->foreign_key_ref]->fields)) {
            $json->forms[$cardinality->foreign_key_ref]->fields[] = $cardinality->foreign_key_field ;
        }        
    }

}

//var_dump($json) ;

/*
$json_str = '
{    
    "forms" : [
        {
            "form_name": "person",
            "fields": ["first_name", "last_name"],
            "cardinality": "singleton"
        }, 
        {
            "form_name": "visit",
            "fields": ["visit_date", "visit_type"],
            "cardinality": "repeating"
        },     
        {
            "form_name": "meds",
            "join_condition": "meds.med_visit_date = visit.visit_date",
            "fields": ["med_visit_date", "medication"],
            "cardinality": "repeating"
        }             
    ]    
} ' ;

$json = json_decode($json_str) ;

echo "Json error :" . json_last_error() ;
*/

// var_dump($json) ;

$select = "" ;
$from = "" ;
$fields = array() ;

$primaryForm = false ;
$primaryFormName = "" ;

foreach ($json->forms as $form) {

    // if ($primaryForm) = ($json->primaryFormName == $form->form_name) ;
    $primaryForm = ($from == "") ;
    if ($primaryForm) {
        $primaryFormName = $form->form_name ;
    }

    echo "Processing form " . $form->form_name . "<br>" ;

    $formSql = ($primaryForm ? " ( select rd.record " : " ( select rd.record, rd.instance ") ;

    foreach($form->fields as $field) {
        $fields[] = $field ;
        $formSql = $formSql . ", max(case when rd.field_name = '" . $field . "' then rd.value end) " . $field . " ";
    }

    if ($form->cardinality == "singleton") {
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
        
        if (isset($form->join_condition)) {
            $from = $from . " and " . $form->join_condition ;
        }

        $from = $from . " ) " ;
    }

    if ( $select == "" ) {
        $select = "Select " . implode(",", $form->fields) ;
    } else {
        $select = $select . ", " . implode(",", $form->fields);
    }
    
    // $select = $select . " " . $form->form_name . ".* " ;

}

$sql = $select . " " . $from ;

if (isset($json->filter)) {
    $sql = $sql . " where " . implode(" and ", $json->filter);
}

echo "SQL to execute :" . $sql ;

$rptdata = db_query($sql) ;

if (strlen(db_error()) > 0) {
    echo "<div style='color:red'>Error in database call :" . db_error() . "</div>";
}

$markup = "<table id='dataTable' border='1'><thead><tr>" ;

foreach ($fields as $field) {
    $markup = $markup . "<th>" . $field . "</th>" ;
}
$markup = $markup . "</tr></thead>" ;

$markup = $markup . "<tbody>" ;
while ($row = db_fetch_assoc($rptdata)) {
    $markup = $markup . "<tr>" ;
    foreach ($fields as $field) {
        $markup = $markup."<td>" . $row[$field] . "</td>";
    }
    $markup = $markup . "</tr>" ;
}
$markup = $markup . "</tbody>" ;
$markup = $markup . "</table>" ;

?>

<!doctype html>
<html lang="en">
<head>
    <title></title>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- JQueryUI and JQuery are included in ProjectGeneral/header.php -->

    <!-- DataTable Implementation -->
    <link rel="stylesheet" type="text/css"
          href="https://cdn.datatables.net/1.10.15/css/jquery.dataTables.min.css"/>
    <script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>

    <!-- local style overrides -->
    <link rel="stylesheet" type="text/css" href="<?php echo $module->getUrl("css/exportStyle.css") ?>"/>

</head>
<body>
<div class="content"  style="max-width:750px; margin-bottom:10px; padding-left: 10px;">
    <div class="row">
        <div class="projhdr">
            <i class="fas fa-download"></i>
            Export Data from Database
        </div>
    </div>
    <div class="row">

        <div class="col-md-8  cardinal emphatic header nowrap text-left " style="min-width:200px">
            <span>Report Name:   <input type="text" id="report_name" size="40"/></span>

        </div>
        <div class="col-md-2 cardinal emphatic header nowrap text-left">
                <button id="save_export" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-download"></i> Export Data </button>
        </div>
    </div>
</div>

<div>
    <?php  echo $markup ?>
</div>

<div class="loader"><!-- Place at bottom of page --></div>

<!-- this renders the JSON used by bstreeview.js to render content into the div with id="tree" -->
<!-- the ordering matters here: this script must run after instruments_and_fields.php has run -->
<script>
    $(document).ready(function() {
        $('#dataTable').DataTable();
    } );

</script>

</body>
</html>