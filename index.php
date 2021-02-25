<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
# Render left hand side navigation and PID / project name banner
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$port = (PORT === '') ? '' : ':'.PORT;
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

        <!-- hierarchical tree control CSS -->
        <link rel="stylesheet" type="text/css" href="<?php echo $module->getUrl("client/css/bstreeview.css") ?>"/>
        <!-- hierarchical tree control function definitions  -->
        <script src="<?php echo $module->getUrl("client/js/bstreeview.js") ?>"></script>
        <!-- javascript with data to drive the left hand side hierarchical tree browser of instruments & fields -->
        <!-- renders into the div with id='tree' -->
        <script id="insert-js-here"></script>
        <!-- local style overrides -->
        <link rel="stylesheet" type="text/css" href="<?php echo $module->getUrl("client/css/exportStyle.css") ?>"/>

        <!-- walk the current UI and convert to Json for persistence and querying the database -->
        <script src="<?php echo $module->getUrl("client/js/controller.js") ?>"></script>

    </head>
    <body>
    <div class="content"  style="margin-bottom:10px; padding-left: 10px; padding-right: 20px;">
        <form name="export-repeating" id="export-repeating">
            <!-- set up for calling REDcap API via Ajax to display controls for user-specified field filters (upper right) -->
            <input type="hidden" name="base-url" id="base-url"
                   value="<?php echo $_SERVER['REQUEST_SCHEME'] . '://' . SERVER_NAME . $port . APP_PATH_WEBROOT . 'DataExport/report_filter_ajax.php?pid=' . PROJECT_ID ?>">
            <input type="hidden" name="redcap_csrf_token" id="redcap_csrf_token"
                   value="<?php echo System::getCsrfToken() ?>">
            <input type="hidden" name="report-submit" id="report-submit"
                   value="<?php echo $module->getUrl("server/getDataFromServer.php") ?>">
            <input type="hidden" name="clientmeta-submit" id="clientmeta-submit"
                   value="<?php echo $module->getUrl("server/getClientMetadata.php") ?>">
            <input type="hidden" name="filter-submit" id="filter-submit"
                   value="<?php echo $module->getUrl("server/getFilterDefns.php") ?>">
            <input type="hidden" name="save-report" id="save-report"
                   value="<?php echo $module->getUrl("server/manageReports.php") ?>">
            <!-- imitate other REDCap page headers -->
            <div class="row">
                <div class="projhdr">
                    <i class="fas fa-download"></i>
                    Review / Export Data
                </div>
            </div>

            <div id="dialog" title="Restore Settings" style="display:none">
                <table id="holder">
                    <tr>
                        <td>Drop files here</td>
                    </tr>
                    <tr>
                        <td>
                            <ul id="fileList"></ul>
                        </td>
                    </tr>
                </table>
            </div>
            <!-- prompt for a report name used to save and restore report settings -->
            <div class="row" style="padding-right: 15px;">

                <div class="col-md-7 col-sm-5  cardinal emphatic header nowrap text-left " style="min-width:200px">
                    <span>Report Name:   <input type="text" id="report_name" name="report_name" size="40"/><select
                                class="ml-3 mb-1 jqbuttonmed  ui-corner-all " name="raw_or_label" id="raw_or_label"><option
                                    value="label">Labels</option><option value="raw">Raw Data</option></select></span>
                </div>
                <div class="col-md-2 col-sm-3 cardinal emphatic header nowrap text-left">
                    <button type="button" onclick="saveExportJson()" id="save_export_json"
                            class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"><i
                                class="fas fa-file-download"></i> Save Settings
                    </button>
                </div>
                <div class="col-md-3  col-sm-3 cardinal emphatic header nowrap text-left">
                    <!--                    <button type="button" onclick="promptForUpload()" id="load_export_json" class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget"> <i class="fas fa-file-upload"></i> Load Settings</button>-->
                    <span>
                            Saved Reports:
                            <select id="saved-reports" onchange="loadSavedReport()">
                            <option value="">Select a Report</option>
                            <?php
                            $reports = json_decode(str_replace("\\n", "", $module->getProjectSetting('saved-reports')), true);
                            foreach ($reports as $name => $report) {
                                ?>
                                <option value='<?php echo json_encode($report) ?>'><?php echo $name ?></option>
                                <?php
                            }
                            ?>
                        </select>
                        </span>
                </div>
            </div>

            <!-- three main components: on the left, a hierarchical tree control, the elements of which can be dragged into
                 either of the two drop targets on the right
                 On the right, on top, a drop target to specify row filters. Below that, specify report columns. -->
            <div class="row">

                <!-- left hand side -->
                <div class="col-md-3 pt-3 overflow-auto" style="max-height: 750px;">
                    <!-- this div is selected by bstreeview.js to render content specified by the Json at the bottom of the page -->
                    <!-- also note we use a customized version of bstreeview that makes each element draggable  -->
                  <div id="treeSearchDiv">
                    <input type="text" id="treeSearch" class="mb-3" placeholder="Search fields">
                  </div>
                  <div id="tree" class="bstreeview " >

                    </div>

                    <div class="mt-20 ">&nbsp;</div>
                    <div class="big-spin spinner-border text-secondary " role="status" id="ui-loading" >
                        <span class="sr-only">Loading...</span>
                    </div>


                </div>

                <!-- right hand side -->
                <div class="col-md-9 pt-3">
                    <span><div class="cardinal">Filter Rows - Drag and Drop from left menu</div>
                    <div class="container droptarget" >
                           <div class="col-12 mt-2"><label>Apply Filters to Data?</label> <input type="radio" id="aftd-yes" name="applyFiltersToData" value="true" checked>
                          <label for="yes">Yes</label>
                          <input type="radio" id="aftd-no" name="applyFiltersToData" value="false" >
                          <label for="female">No</label><div id="count-display" class="mr-1" style="float:right"> matching records: 0</div><button type="button" class="mr-1 mt-1 badge badge-light" style="float:right" onclick="runQuery(false, true)">count</button>
                        <div class="spinner-border text-secondary mt-1 mr-1 mini-spin" role="status" id="count-running" style="display: none; float:right">
                            <span class="sr-only">Loading...</span>
                        </div></div>
                    </span>
                    <div id="row_filter" class="list-group filters-fields " style="min-height: 50px; width: 100%;">
                        <div class="grey cbox-panel" id="tip_exporting_all_rows"><span >Currently exporting all rows. </span><span id="tip_missing_col_1">You must specify at least one column below.</span></div>

                        </div>
                        <div id="insert-row-filters-here"></div>
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

                    <div class="col-md-9 ml-n2 mt-5">
                        <table width="100%"><tr><td>
                                    <button type="button"  onclick="runQuery(true, false)"  id="preview" class=" jqbuttonmed ui-button ui-corner-all ui-widget">
                                        <i class="fas fa-eye"></i> Preview Data
                                    </button>
                                </td><td>
                                    <button type="button" id="do_export" onclick="runQuery(false, false)"  class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget" style="">
                                        <i class="fas fa-file-download"></i> Export Data
                                    </button>
                                </td></tr>
                            <tr><td>
                                    <div class="spinner-border text-secondary mt-1 ml-5" role="status" id="longop-running" style="display:none">
                                        <span class="sr-only">Loading...</span>
                                    </div>
                                </td><td>
                                    <div class="spinner-border text-secondary mt-1 ml-5" role="status" id="export-running" style="display:none">
                                        <span class="sr-only">Loading...</span>
                                    </div>
                                </td></tr>
                        </table>

                    </div>






                <div class =" ml-n2 mt-5" id="data-error">
                        <div id="data-error-message"></div>
                    </div>




                    <!-- if preview is specified, this is where the preview appears -->
                <div class="col-md-9 pt-5">
                    <div id="datatable"  style="display: none;">
                        <p>
                            Your data can be previewed below.<br/> If this is not the format you were expecting, you can
                            adjust the specification above and try again.
                        </p>

                        <div id="preview-table-div"></div>

                        <p></p>
                        <button type="button" id="do_export2" onclick="runQuery(false, false)"  class="data_export_btn jqbuttonmed ui-button ui-corner-all ui-widget" > <i class="fas fa-file-download"></i> Export Data</button>

                    </div>

                </div>

            </div>
        </form>
    </div>

    </body>
</html>
