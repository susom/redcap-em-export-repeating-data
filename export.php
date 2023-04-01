<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */

// export data as csv file
$action = isset($_POST['action']) && !empty($_POST['action']) ? $_POST['action'] : null;

$inDownloadMode = ($action == "downloadReport") ;

# Render Table Page
if (!$inDownloadMode) {

    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
}


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

        {
            "instrument": "pft",
            "cardinality": "repeating-secondary ",
            "join_type": "date_proximity",
            "join_field": "pft_test_date",
            "foreign_key_field": "visit_date",
            "foreign_key_ref": "visit"
        }

,
        "filters": [{
                "instrument": "person",
                "field": "record",
                "operator": "E",
                "param1": "1",
                "param2": ""
            }
        ]
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
        ]
    }
' ;


$json_inp = json_decode($json_str) ;
$json = json_decode('{ "forms" : []}') ;
if (!$inDownloadMode) echo "Json error1 :" . json_last_error() ;

# columns array
foreach ($json_inp->columns as $column) {
    if (!isset($json->forms[$column->instrument])) {
        $json->forms[$column->instrument] = json_decode('{ "fields" : [] }') ;
        $json->forms[$column->instrument]->form_name = $column->instrument ;
    }
    $json->forms[$column->instrument]->fields[] = $column->field ;
}

# cardinality array
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

# filters array
foreach ($json_inp->filters as $filter) {

    $filstr = $filter->instrument . "." . $filter->field . " " ;

    if ($filter->operator == "E")
        $filstr = $filstr . " = '" . db_escape($filter->param1) . "'" ;
    elseif ($filter->operator == "NE")
        $filstr = $filstr . " <> '" . db_escape($filter->param1) . "'" ;
    elseif ($filter->operator == "CONTAINS")
        $filstr = $filstr . " like '%" . db_escape($filter->param1) . "%'" ;
    elseif ($filter->operator == "NOT_CONTAIN")
        $filstr = $filstr . " not like '%" . db_escape($filter->param1) . "%'" ;
    elseif ($filter->operator == "STARTS_WITH")
        $filstr = $filstr . " like '" . db_escape($filter->param1) . "%'" ;
    elseif ($filter->operator == "ENDS_WITH")
        $filstr = $filstr . " like '%" . db_escape($filter->param1) . "'" ;
    elseif ($filter->operator == "LT")
        $filstr = $filstr . " < '" . db_escape($filter->param1) . "'" ;
    elseif ($filter->operator == "LTE")
        $filstr = $filstr . " <= '" . db_escape($filter->param1) . "'" ;
    elseif ($filter->operator == "GT")
        $filstr = $filstr . " > '" . db_escape($filter->param1) . "'" ;
    elseif ($filter->operator == "GTE")
        $filstr = $filstr . " >= '" . db_escape($filter->param1) . "'" ;

    $json->filters[] = $filstr ;
}

// var_dump($json) ;

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

$json_str = '
{
    "forms" : [
        {
            "form_name": "patient_information",
            "fields": ["record_id","first_name","last_name","middle_name","mrn","dob","death_date","gender","ssn_suffix","race","ethnicity","city","state","zip","email","patient_information_complete"],
            "cardinality": "singleton"
        },
        {
            "form_name": "surgical_data_hipknee",
            "fields": ["surgical_data_unique_key","surgery_type","surfirstname","surlastname","surnpi","pat_height_cm","pat_weight_kg","pat_height_ftin","pat_weight_lbs","pat_bmi","proceduredate","admsndt","dschrgdt","dschdispcode","px_1","px_1_name","px_2","px_2_name","px_3","px_3_name","px_4","px_4_name","px_5","px_5_name","px_6","px_6_name","px_7","px_7_name","px_8","px_8_name","px_9","px_9_name","px_10","px_10_name","dx_1","dx_1_name","dxlat","surgicalapproach","techniquehip","techniqueknee","procedurestarttime","procedureendtime","procedureduration","computernavigation","roboticassistedsurgery","lengthofstay","anesthesiatype","periarticularinjection","mfg_1","componentname_1","cat_1","lot_1","mfg_2","componentname_2","cat_2","lot_2","mfg_3","componentname_3","cat_3","lot_3","mfg_4","componentname_4","cat_4","lot_4","mfg_5","componentname_5","cat_5","lot_5","mfg_6","componentname_6","cat_6","lot_6","mfg_7","componentname_7","cat_7","lot_7","mfg_8","componentname_8","cat_8","lot_8","mfg_9","componentname_9","cat_9","lot_9","mfg_10","componentname_10","cat_10","lot_10","mfg_11","componentname_11","cat_11","lot_11","mfg_12","componentname_12","cat_12","lot_12","mfg_13","componentname_13","cat_13","lot_13","mfg_14","componentname_14","cat_14","lot_14","mfg_15","componentname_15","cat_15","lot_15","mfg_16","componentname_16","cat_16","lot_16","mfg_17","componentname_17","cat_17","lot_17","mfg_18","componentname_18","cat_18","lot_18","mfg_19","componentname_19","cat_19","lot_19","mfg_20","componentname_20","cat_20","lot_20","asa_class","com_1","com_1_name","poa_1","com_2","com_2_name","poa_2","com_3","com_3_name","poa_3","com_4","com_4_name","poa_4","com_5","com_5_name","poa_5","com_6","com_6_name","poa_6","com_7","com_7_name","poa_7","com_8","com_8_name","poa_8","com_9","com_9_name","poa_9","com_10","com_10_name","poa_10","com_11","com_11_name","poa_11","com_12","com_12_name","poa_12","com_13","com_13_name","poa_13","com_14","com_14_name","poa_14","com_15","com_15_name","poa_15","com_16","com_16_name","poa_16","com_17","com_17_name","poa_17","com_18","com_18_name","poa_18","com_19","com_19_name","poa_19","com_20","com_20_name","poa_20","surgical_data_hipknee_complete"],
            "cardinality": "repeating"
        },
        {
            "form_name": "postop_surgical_data",
            "fields": ["postop_data_unique_key","proceduredate_pop","joint_pop","dxlat_pop","re_pat_height_cm_pop","re_pat_weight_kg_pop","re_pat_height_ftin_pop","re_pat_weight_lbs_pop","re_pat_bmi_pop","re_admsndt_pop","re_dschrgdt_pop","re_dschdispcode_pop","re_lengthofstay_pop","re_proceduredate_pop","re_px_1_pop","re_px_1_pop_name","re_px_2_pop","re_px_2_pop_name","re_px_3_pop","re_px_3_pop_name","re_px_4_pop","re_px_4_pop_name","re_px_5_pop","re_px_5_pop_name","re_px_6_pop","re_px_6_pop_name","re_px_7_pop","re_px_7_pop_name","re_px_8_pop","re_px_8_pop_name","re_px_9_pop","re_px_9_pop_name","re_px_10_pop","re_px_10_pop_name","re_dx_1_pop","re_dx_1_pop_name","re_poa_1_pop","re_dx_2_pop","re_dx_2_pop_name","re_poa_2_pop","re_dx_3_pop","re_dx_3_pop_name","re_poa_3_pop","re_dx_4_pop","re_dx_4_pop_name","re_poa_4_pop","re_dx_5_pop","re_dx_5_pop_name","re_poa_5_pop","re_dx_6_pop","re_dx_6_pop_name","re_poa_6_pop","re_dx_7_pop","re_dx_7_pop_name","re_poa_7_pop","re_dx_8_pop","re_dx_8_pop_name","re_poa_8_pop","re_dx_9_pop","re_dx_9_pop_name","re_poa_9_pop","re_dx_10_pop","re_dx_10_pop_name","re_poa_10_pop","re_dx_11_pop","re_dx_11_pop_name","re_poa_11_pop","re_dx_12_pop","re_dx_12_pop_name","re_poa_12_pop","re_dx_13_pop","re_dx_13_pop_name","re_poa_13_pop","re_dx_14_pop","re_dx_14_pop_name","re_poa_14_pop","re_dx_15_pop","re_dx_15_pop_name","re_poa_15_pop","re_dx_16_pop","re_dx_16_pop_name","re_poa_16_pop","re_dx_17_pop","re_dx_17_pop_name","re_poa_17_pop","re_dx_18_pop","re_dx_18_pop_name","re_poa_18_pop","re_dx_19_pop","re_dx_19_pop_name","re_poa_19_pop","re_dx_20_pop","re_dx_20_pop_name","re_poa_20_pop","re_dx_21_pop","re_dx_21_pop_name","re_poa_21_pop","re_dx_22_pop","re_dx_22_pop_name","re_poa_22_pop","re_dx_23_pop","re_dx_23_pop_name","re_poa_23_pop","re_dx_24_pop","re_dx_24_pop_name","re_poa_24_pop","re_dx_25_pop","re_dx_25_pop_name","re_poa_25_pop","re_dx_26_pop","re_dx_26_pop_name","re_poa_26_pop","re_dx_27_pop","re_dx_27_pop_name","re_poa_27_pop","re_dx_28_pop","re_dx_28_pop_name","re_poa_28_pop","re_dx_29_pop","re_dx_29_pop_name","re_poa_29_pop","re_dx_30_pop","re_dx_30_pop_name","re_poa_30_pop","postop_surgical_data_complete"],
            "cardinality": "repeating"
        }
    ]
} ' ;

$json = json_decode($json_str) ;

echo "Json error :" . json_last_error() ;
*/

// var_dump($json) ;

$json_str = '
{    
    "forms" : [
        {
            "form_name": "patient_information",
            "fields": ["record_id","first_name","last_name","middle_name","mrn","dob","death_date","gender","ssn_suffix","race","ethnicity","city","state","zip","email","patient_information_complete"],
            "cardinality": "singleton"
        }, 
        {
            "form_name": "surgical_data_hipknee",
            "fields": ["surgical_data_unique_key","surgery_type","surfirstname","surlastname","surnpi","pat_height_cm","pat_weight_kg","pat_height_ftin","pat_weight_lbs","pat_bmi","proceduredate","admsndt","dschrgdt","dschdispcode","px_1","px_1_name","px_2","px_2_name","px_3","px_3_name","px_4","px_4_name","px_5","px_5_name","px_6","px_6_name","px_7","px_7_name","px_8","px_8_name","px_9","px_9_name","px_10","px_10_name","dx_1","dx_1_name","dxlat","surgicalapproach","techniquehip","techniqueknee","procedurestarttime","procedureendtime","procedureduration","computernavigation","roboticassistedsurgery","lengthofstay","anesthesiatype","periarticularinjection","mfg_1","componentname_1","cat_1","lot_1","mfg_2","componentname_2","cat_2","lot_2","mfg_3","componentname_3","cat_3","lot_3","mfg_4","componentname_4","cat_4","lot_4","mfg_5","componentname_5","cat_5","lot_5","mfg_6","componentname_6","cat_6","lot_6","mfg_7","componentname_7","cat_7","lot_7","mfg_8","componentname_8","cat_8","lot_8","mfg_9","componentname_9","cat_9","lot_9","mfg_10","componentname_10","cat_10","lot_10","mfg_11","componentname_11","cat_11","lot_11","mfg_12","componentname_12","cat_12","lot_12","mfg_13","componentname_13","cat_13","lot_13","mfg_14","componentname_14","cat_14","lot_14","mfg_15","componentname_15","cat_15","lot_15","mfg_16","componentname_16","cat_16","lot_16","mfg_17","componentname_17","cat_17","lot_17","mfg_18","componentname_18","cat_18","lot_18","mfg_19","componentname_19","cat_19","lot_19","mfg_20","componentname_20","cat_20","lot_20","asa_class","com_1","com_1_name","poa_1","com_2","com_2_name","poa_2","com_3","com_3_name","poa_3","com_4","com_4_name","poa_4","com_5","com_5_name","poa_5","com_6","com_6_name","poa_6","com_7","com_7_name","poa_7","com_8","com_8_name","poa_8","com_9","com_9_name","poa_9","com_10","com_10_name","poa_10","com_11","com_11_name","poa_11","com_12","com_12_name","poa_12","com_13","com_13_name","poa_13","com_14","com_14_name","poa_14","com_15","com_15_name","poa_15","com_16","com_16_name","poa_16","com_17","com_17_name","poa_17","com_18","com_18_name","poa_18","com_19","com_19_name","poa_19","com_20","com_20_name","poa_20","surgical_data_hipknee_complete"],
            "cardinality": "repeating"
        },     
        {
            "form_name": "postop_surgical_data",
            "fields": ["postop_data_unique_key","proceduredate_pop","joint_pop","dxlat_pop","re_pat_height_cm_pop","re_pat_weight_kg_pop","re_pat_height_ftin_pop","re_pat_weight_lbs_pop","re_pat_bmi_pop","re_admsndt_pop","re_dschrgdt_pop","re_dschdispcode_pop","re_lengthofstay_pop","re_proceduredate_pop","re_px_1_pop","re_px_1_pop_name","re_px_2_pop","re_px_2_pop_name","re_px_3_pop","re_px_3_pop_name","re_px_4_pop","re_px_4_pop_name","re_px_5_pop","re_px_5_pop_name","re_px_6_pop","re_px_6_pop_name","re_px_7_pop","re_px_7_pop_name","re_px_8_pop","re_px_8_pop_name","re_px_9_pop","re_px_9_pop_name","re_px_10_pop","re_px_10_pop_name","re_dx_1_pop","re_dx_1_pop_name","re_poa_1_pop","re_dx_2_pop","re_dx_2_pop_name","re_poa_2_pop","re_dx_3_pop","re_dx_3_pop_name","re_poa_3_pop","re_dx_4_pop","re_dx_4_pop_name","re_poa_4_pop","re_dx_5_pop","re_dx_5_pop_name","re_poa_5_pop","re_dx_6_pop","re_dx_6_pop_name","re_poa_6_pop","re_dx_7_pop","re_dx_7_pop_name","re_poa_7_pop","re_dx_8_pop","re_dx_8_pop_name","re_poa_8_pop","re_dx_9_pop","re_dx_9_pop_name","re_poa_9_pop","re_dx_10_pop","re_dx_10_pop_name","re_poa_10_pop","re_dx_11_pop","re_dx_11_pop_name","re_poa_11_pop","re_dx_12_pop","re_dx_12_pop_name","re_poa_12_pop","re_dx_13_pop","re_dx_13_pop_name","re_poa_13_pop","re_dx_14_pop","re_dx_14_pop_name","re_poa_14_pop","re_dx_15_pop","re_dx_15_pop_name","re_poa_15_pop","re_dx_16_pop","re_dx_16_pop_name","re_poa_16_pop","re_dx_17_pop","re_dx_17_pop_name","re_poa_17_pop","re_dx_18_pop","re_dx_18_pop_name","re_poa_18_pop","re_dx_19_pop","re_dx_19_pop_name","re_poa_19_pop","re_dx_20_pop","re_dx_20_pop_name","re_poa_20_pop","re_dx_21_pop","re_dx_21_pop_name","re_poa_21_pop","re_dx_22_pop","re_dx_22_pop_name","re_poa_22_pop","re_dx_23_pop","re_dx_23_pop_name","re_poa_23_pop","re_dx_24_pop","re_dx_24_pop_name","re_poa_24_pop","re_dx_25_pop","re_dx_25_pop_name","re_poa_25_pop","re_dx_26_pop","re_dx_26_pop_name","re_poa_26_pop","re_dx_27_pop","re_dx_27_pop_name","re_poa_27_pop","re_dx_28_pop","re_dx_28_pop_name","re_poa_28_pop","re_dx_29_pop","re_dx_29_pop_name","re_poa_29_pop","re_dx_30_pop","re_dx_30_pop_name","re_poa_30_pop","postop_surgical_data_complete"],
            "cardinality": "repeating"
        }
    ]    
} ' ;

$json = json_decode($json_str) ;


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

    // echo "Processing form " . $form->form_name . "<br>" ;

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

if (isset($json->filters)) {
    $sql = $sql . " where " . implode(" and ", $json->filters);
}

if (!$inDownloadMode) echo "SQL to execute :" . $sql ;


if ($action == "generateReport" || $action == "downloadReport") {

    $rptdata = db_query($sql) ;

    if (strlen(db_error()) > 0) {
        echo "<div style='color:red'>Error in database call :" . db_error() . "</div>";
    }
}


if ($action === "downloadReport") {

    $filename = sprintf('%1$s-%2$s-%3$s.csv', str_replace(' ', '', $project_name), date('Ymd'), date('His'));

    //$filename = 'Podaanga.csv' ;

    // Output CSV-specific headers
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Transfer-Encoding: binary');

    // Open the output stream
    $fh = fopen('php://output', 'w');

    // Start output buffering (to capture stream contents)
    ob_start();

    // CSV Header
    fputcsv($fh, $fields);


    // CSV Data
    $rowCount = 0 ;
    while ($row = db_fetch_assoc($rptdata)) {

        fputcsv($fh, $row) ;

        if ($rowCount == 10) {
            $string = ob_get_clean();
            echo $string ;
            $rowCount = 0 ;
        }

        $rowCount++ ;
    }

    // Get the contents of the output buffer
    $string = ob_get_clean();

    echo $string ;

    // Stream the CSV data
    exit();

}

if ($action == "generateReport") {
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
}
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

        <div class="col-md-8  cardinal emphatic header nowrap text-start " style="min-width:200px">
            <span>Report Name:   <input type="text" id="report_name" size="40"/></span>

        </div>
        <div class="col-md-2 cardinal emphatic header nowrap text-start">
            <form method="post">
                <input type="hidden" name="action" value="generateReport">
                <button id="view_export" type="submit" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-download"></i> View Data </button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="downloadReport">
                <button id="save_export" type="submit" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-download"></i> Export Data </button>
            </form>
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
