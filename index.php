<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
# Render Table Page
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

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

    <!-- hierarchical tree control -->
    <link rel="stylesheet" type="text/css" href="<?php echo $module->getUrl("css/bstreeview.css") ?>"/>
    <script src="<?php echo $module->getUrl("js/bstreeview.js") ?>"></script>

    <!-- javascript with data to drive the left hand side hierarchical tree browser of instruments & fields -->
    <?php
    require_once($module->getModulePath() . "view/instruments_and_fields.php");
    ?>
    <!-- local setup for drag n drop. Note this must occur after the inclusion of instruments_and_fields.php -->
    <script src="<?php echo $module->getUrl("js/dragNdrop.js") ?>"></script>

    <!-- local style overrides -->
    <link rel="stylesheet" type="text/css" href="<?php echo $module->getUrl("css/exportStyle.css") ?>"/>

</head>
<body>
<div class="content"  style="max-width:750px; margin-bottom:10px; padding-left: 10px;">
    <div class="row">
        <div class="projhdr">
            <i class="fas fa-download"></i>
            Review / Export Data
        </div>
    </div>
    <div class="row">

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

    <div class="row">

        <div class="col-md-3 pt-3">
            <!-- this div is selected by bstreeview.js to render content specified by the Json at the bottom of the page -->
            <!-- also note we use a customized version of bstreeview that makes each element draggable  -->
            <div id="tree" class="bstreeview" >

            </div>
        </div>
        <div class="col-md-9 pt-3">
            <div class="cardinal">Filter Rows - Drag and Drop from left menu</div>
            <div class="container" style="border:1px solid #cecece;">
                <ul id="row_filter" class="list-group filters-fields connectedSortable sortable" style="    min-height: 50px;
    width: 100%;">
                    <div class="grey"><span id="tip_exporting_all_rows">Currently exporting all rows. </span><span id="tip_missing_col_1">You must specify at least one column below.</span></div>

                </ul>
            </div>

            <p></p>

            <div class="cardinal">Specify Report Columns - Drag and Drop from left menu</div>
            <div class="container" style="border:1px solid #cecece;">
                <div id="column_spec" class="list-group filters-fields connectedSortable sortable" style="    min-height: 200px;
    width: 100%;">
                    <div class="grey" id="tip_missing_col_2">Tip: drop an instrument to select all its fields
                        <br/>
                        Instruments are tagged with a folder icon : <span class="fas fa-folder"></span></div>
                </div>
            </div>
        </div>

    </div>
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

    $( function() {
        // $( ".draggable" ).draggable({ opacity: 0.7, helper: "clone" });
        // $( ".sortable" ).sortable();
        // $( ".sortable" ).disableSelection();
        // // $( "#sortable3" ).sortable();
        // // $( "#sortable3" ).disableSelection();
        //
        //
        //
        // $( "body" ).on( ".draggable dragstart",
        //     function( event, ui ) {
        //         dropped = false;
        //       //  console.log('1');
        //         ui.helper.before(ui.helper.clone().draggable());
        //     }
        // );
        //
        // $( "body" ).on( ".draggable dragstop",
        //     function( event, ui ) {
        //         if(dropped)
        //             ui.helper.draggable('destroy');
        //         else
        //             ui.helper.remove();
        //     }
        // );
        //
        // $(".instruments-fields").sortable({
        //     connectWith: ".connectedSortable",
        //     stop: function (event, ui) {
        //         var $element = $(ui.item[0]);
        //     },
        //     remove: function (event, ui) {
        //         var $newELem = ui.item.clone();
        //         CorrelatedReportConfig.appendInputs($newELem);
        //         $newELem.appendTo('.filters-fields');
        //         $(this).sortable('cancel');
        //     }
        // });
        //
        // $('.connectedSortable').droppable({
        //     accept: '.draggable',
        //     drop: function(event, ui ) {
        //         dropped = true;
        //     }
        // });
        //
        //
        // $( "#destinationA ol" ).droppable(
        //     {
        //         activeClass: "ui-state-default",
        //         hoverClass: "ui-state-hover",
        //         accept: ":not(.ui-sortable-helper)",
        //         drop: function( event, ui )
        //         {
        //             // Get id of the item that was moved
        //             var itemIdMoved = ui.draggable.data('item-id');
        //             var itemName = ui.draggable.data('item-name');
        //
        //             // Get id of the current destination (the item is dropped into the ul tag so we need to go up a level to get the div id)
        //             var destinationId = $(this).parent().attr('id');
        //
        //             // Move the draggable into the destination box (actually moves the original li element including data attributes)
        //             ui.draggable.appendTo(this);
        //
        //             // Debug
        //             console.log('item ' + itemName + ' (id: ' + itemIdMoved + ') dropped into ' + destinationId);
        //         }
        //     }).sortable(
        //     {
        //         sort: function()
        //         {
        //             // gets added unintentionally by droppable interacting with sortable
        //             // using connectWithSortable fixes this, but doesn't allow you to customize active/hoverClass options
        //             $( this ).removeClass( "ui-state-default" );
        //         }
        //     });


    } );
</script>

</body>
</html>