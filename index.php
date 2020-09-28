<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
# Render left hand side navigation and PID / project name banner
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

?>
<!doctype html>
<html lang="en">
<head>
    <title></title>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <input type="hidden" name="redcap_csrf_token" id="redcap_csrf_token" value="<?php echo System::getCsrfToken() ?>">
    <!-- JQueryUI and JQuery are included in ProjectGeneral/header.php -->

    <!-- DataTable Implementation -->
    <link rel="stylesheet" type="text/css"
          href="https://cdn.datatables.net/1.10.15/css/jquery.dataTables.min.css"/>
    <script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>

    <!-- hierarchical tree control CSS -->
    <link rel="stylesheet" type="text/css" href="<?php echo $module->getUrl("client/css/bstreeview.css") ?>"/>
    <!-- hierarchical tree control function definitions  -->
    <script src="<?php echo $module->getUrl("client/js/bstreeview.js") ?>"></script>
    <!-- javascript with data to drive the left hand side hierarchical tree browser of instruments & fields -->
    <!-- renders into the div with id='tree' -->
    <?php
    require_once($module->getModulePath() . "client/include/instruments_and_fields.php");
    ?>

    <!-- local setup for drag n drop. Note this must occur after the inclusion of instruments_and_fields.php -->
    <?php
    require_once($module->getModulePath() . "client/include/drag_n_drop.php");
    ?>

    <!-- local style overrides -->
    <link rel="stylesheet" type="text/css" href="<?php echo $module->getUrl("client/css/exportStyle.css") ?>"/>

</head>
<body>
<div class="content"  style="margin-bottom:10px; padding-left: 10px; padding-right: 20px;">
    <!-- set up for calling REDcap API via Ajax to display controls for user-specified field filters (upper right) -->
    <input type="hidden" name="base-url" id="base-url"
           value="<?php echo $_SERVER['REQUEST_SCHEME'] . '://' . SERVER_NAME . APP_PATH_WEBROOT . 'DataExport/report_filter_ajax.php?pid=' . PROJECT_ID ?>">
    <input type="hidden" name="redcap_csrf_token" id="redcap_csrf_token" value="<?php echo System::getCsrfToken() ?>">

    <!-- imitate other REDCap page headers -->
    <div class="row" >
        <div class="projhdr">
            <i class="fas fa-download"></i>
            Review / Export Data
        </div>
    </div>

    <!-- prompt for a report name used to save and restore report settings -->
    <div class="row" style="padding-right: 15px;">

        <div class="col-md-8  cardinal emphatic header nowrap text-left " style="min-width:200px">
            <span>Report Name:   <input type="text" id="report_name" size="40"/></span>

        </div>
        <div class="col-md-2 cardinal emphatic header nowrap text-left">
                <button id="save_export_xml" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-download"></i> Save XML</button>
        </div>
        <div class="col-md-2 cardinal emphatic header nowrap text-left">
            <button id="load_export_xml" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-upload"></i> Load XML</button>
        </div>
    </div>

    <!-- three main components: on the left, a hierarchical tree control, the elements of which can be dragged into
         either of the two drop targets on the right
         On the right, on top, a drop target to specify row filters. Below that, specify report columns. -->
    <div class="row">

        <!-- left hand side -->
        <div class="col-md-3 pt-3">
            <!-- this div is selected by bstreeview.js to render content specified by the Json at the bottom of the page -->
            <!-- also note we use a customized version of bstreeview that makes each element draggable  -->
            <div id="tree" class="bstreeview" >

            </div>
        </div>

        <!-- right hand side -->
        <div class="col-md-9 pt-3">
            <div class="cardinal">Filter Rows - Drag and Drop from left menu</div>
            <div class="container droptarget" >
                <div id="row_filter" class="list-group filters-fields " style="min-height: 50px; width: 100%;">
                    <div class="grey cbox-panel" id="tip_exporting_all_rows"><span >Currently exporting all rows. </span><span id="tip_missing_col_1">You must specify at least one column below.</span></div>

                </div>
            </div>

            <p></p>

            <div class="cardinal">Specify Report Columns - Drag and Drop from left menu</div>
            <div class="container droptarget" >
                <div id="column_spec" class="list-group filters-fields " style="min-height: 200px; width: 100%;">
                    <div class="grey cbox-panel" id="tip_missing_col_2">Tip: drop an instrument to select all its fields
                        <br/>
                        Instruments are tagged with this icon : <span class="fas fa-file-alt"></span></div>

                    <!-- generated panels, one for each instrument, to select columns. Hidden on page load -->
                    <?php
                    require_once($module->getModulePath() . "client/include/column_selection_panels.php");
                    ?>
                </div>
            </div>
        </div>

    </div>

    <!-- below the report specification, show buttons to trigger data preview or actual export -->
    <div class="row">
        <div class="col-md-3 pt-5">
            <button id="clickme" class=" jqbuttonmed ui-button ui-corner-all ui-widget">
                <i class="fas fa-eye"></i> Preview Data
            </button>
        </div>
        <div class="col-md-3 pt-5">
            <button id="do_export" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-download"></i> Export Data</button>

        </div>
    </div>

    <!-- if preview is specified, this is where the preview appears -->
    <div class ="row">

        <div class="col-md-12 pt-5">
            <div id="datatable"  >
                <p>
                    Your data can be previewed below.<br/> If this is not the format you were expecting, you can
                    adjust the specification above and try again.
                </p>

                <table id="example" class="display" style="width:100%">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Office</th>
                        <th>Age</th>
                        <th>Start date</th>
                        <th>Salary</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>Tiger Nixon</td>
                        <td>System Architect</td>
                        <td>Edinburgh</td>
                        <td>61</td>
                        <td>2011/04/25</td>
                        <td>$320,800</td>
                    </tr>

                    <tr>
                        <td>Tammy Twotone</td>
                        <td>Software Developer</td>
                        <td>Palo Alto</td>
                        <td>22</td>
                        <td>2020/09/13</td>
                        <td>$120,000</td>
                    </tr>

                    </tbody>
                    <tfoot>
                    <tr>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Office</th>
                        <th>Age</th>
                        <th>Start date</th>
                        <th>Salary</th>
                    </tr>
                    </tfoot>
                </table>

                <p></p>
                <button id="do_export2" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-download"></i> Export Data</button>


            </div>

        </div>
    </div>
</div>

<div class="loader"><!-- Place at bottom of page --></div>

<!-- this renders the JSON used by bstreeview.js to render content into the div with id="tree" -->
<!-- the ordering matters here: this script must run after instruments_and_fields.php has run -->
<script>
    $(document).ready(function() {
        $('#example').DataTable();
    } );
    $( "#datatable" ).hide();
    $( "#clickme" ).click(function() {
        $( "#datatable" ).toggle();
    });

</script>

</body>
</html>

<?php
// ok, now that the page has rendered, do a bit more initialization to prepare for the
// eventual request. This call makes a round trip to the database which takes a noticeable amount of time
// so delay it until after the page has been fully rendered
// TODO refactor to use Ajax
$module->initMeta();