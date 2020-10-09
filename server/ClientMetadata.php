<?php

namespace Stanford\ExportRepeatingData;
/** @var ExportRepeatingData $module */


/**
 * Class ClientMetadata
 * @package Stanford\ExportRepeatingData
 *
 */
class ClientMetadata
{
    private $starttime;

    private $dataDict;

    private $instruments;

    function getClientMetadata() {
        global $module;
        // start debug setup part 1
// microtime(true) returns the unix timestamp plus milliseconds as a float
        $this->starttime = microtime(true);
        $module->emDebug("getClientMetadata launching");
// end debug setup part 1

        $this->dataDict = $module->getDataDictionary();
        $this->instruments = $module->getInstrumentNames();
        $module->emDebug("in ClientMetadata.getClientMetadata");
        $json = '{"a":"b}';

        $jb = json_encode($json);
        $module->emDebug(print_r($jb,TRUE));
        header("content-type: application/html");?>
        <script>

            var instrumentLookup;
            function getInstrumentForField(fieldOrInstrumentName) {
                // console.log('fieldOrInstrumentName');
                // console.log(fieldOrInstrumentName);
                if (! instrumentLookup) {
                    instrumentLookup = [];
                    instrumentLookup['url'] = "<?php echo $module->getPrefix()?>" + '/DataEntry/record_home.php?pid=' +
                        "<?php echo $module->getProjectId()?>" + '&arm=1&id=';
                    <?php
                    foreach ($this->instruments as $key => $instrument) {
                    ?>
                    // this is the date field used for correlated joins
                    instrumentLookup["<?php echo $key ?>_@date_field"] = "<?php echo $module->getDateField($key) ?>";
                    // this entry is used when a folder name is dropped into the columns panel
                    instrumentLookup["<?php echo $instrument ?>"] = "<?php echo $key ?>";
                    //  this idempotent entry is actually used, when de-selecting all checkbox values
                    //    when a panel is hidden by clicking the x in the upper right
                    instrumentLookup["<?php echo $key ?>"] = "<?php echo $key ?>";
                    <?php
                    $fields = $module->getFieldNames($key);
                    foreach ($fields as $field) {
                    ?>
                    instrumentLookup["<?php echo $field ?>"] = "<?php echo $key ?>";
                    instrumentLookup["<?php echo $field ?>@validation"] = "<?php echo $module->getValidation($field) ?>";
                    <?php
                    }
                    }
                    ?>
                }
                return instrumentLookup[fieldOrInstrumentName];
            }

            function  appendInputs(element) {
                var fieldname = element.html();
                $.ajax({
                    url: $("#base-url").val(),
                    data: {field_name: fieldname, redcap_csrf_token: $("#redcap_csrf_token").val()},
                    type: 'POST',
                    success: function (data) {
                        data = '<input type="hidden" name="field_name" value="'+fieldname+'"/>'
                            + data + appendFieldFilterControls();
                        element.append(data);
                    },
                    error: function (request, error) {
                        alert("Request: " + JSON.stringify(request));
                    }
                });
            }

            function tagRepeatables() {
                // use sort order to specify the status of repeatable forms
                // scan the list for an instantiation of the referenced table
                // only use tier-2 if the referenced table is present in the list
                // otherwise use tier-3

                var firstRepeatingPanel = true;
                var targetDate;
                var repeatingForms = [];
                // set the appropriate classes for panel heading coloring and show/hide the correct badge
                $(".panel:visible").each(function() {
                    instrumentName= $(this).attr('id').substr(6);

                    if ($( this ).find(".repeating-primary").length !== 0) {
                        repeatingForms.push(instrumentName);

                        if (firstRepeatingPanel) {
                            // hide secondary badge and show primary badge
                            $(this).find(".repeating-primary").show();
                            $(this).find(".repeating-secondary").hide();
                            $(this).find(".repeating-tertiary").hide();
                            $(this).find(".panel-heading").addClass('tier-1');
                            $(this).find(".panel-heading").removeClass('tier-2');
                            $(this).find(".panel-heading").removeClass('tier-3');
                            firstRepeatingPanel = false;

                            targetDate = getInstrumentForField(instrumentName + '_@date');
                        } else {

                            $(this).find(".repeating-primary").hide();
                            $(this).find(".target-date").replaceWith("<span class='target-date'> after " + targetDate + " (days)</span>");
                            var secondaryHeader = $(this).find(".repeating-secondary");
                            var tertiaryHeader = $(this).find(".repeating-tertiary");
                            secondaryHeader.show();
                            var panelHeading = $(this).find(".panel-heading");
                            panelHeading.removeClass('tier-1');
                            if (panelHeading.hasClass('ref-tier-2')) {
                                // look for the instance linked panel; only tag as tier-3 if present
                                var badge = $(this).find(".badge-primary");
                                var linkedToInstrument = badge.text().substr(22);
                                // console.log(instrumentName + ' linked to '+ linkedToInstrument);
                                // sigh. "linkedToInstrument in repeatingForms" should work but does not
                                // so do it the hard way
                                linkedInstrumentFound  = false;
                                for (let i = 0; i < repeatingForms.length; i++) {
                                    linkedInstrumentFound = linkedInstrumentFound || repeatingForms[i] === linkedToInstrument
                                }

                                if (linkedInstrumentFound) {
                                    secondaryHeader.show();
                                    panelHeading.addClass('tier-2');
                                    tertiaryHeader.hide();
                                    panelHeading.removeClass('tier-3');
                                } else {
                                    tertiaryHeader.show();
                                    panelHeading.addClass('tier-3');
                                    secondaryHeader.hide();
                                    panelHeading.removeClass('tier-2');
                                }
                            } else {
                                panelHeading.addClass('tier-3');
                                tertiaryHeader.show();
                            }
                        }
                    }
                });

            }
            function appendFieldFilterControls  () {
                return '<select name="limiter_connector[]"><option value="AND">AND</option><option value="OR">OR</option></select><button type="button" class="delete-criteria close" aria-label="Close">\n' +
                    '  <span aria-hidden="true">&times;</span>\n' +
                    '</button>'
            }

            function tickAllPanelCheckboxes (label, value) {
                var selector1 = "." + getInstrumentForField(label);
                var selector2 = "#" + getInstrumentForField(label);
                var checkBoxes = $( selector1 );
                checkBoxes.prop("checked", value);
                checkBoxes = $( selector2 );
                checkBoxes.prop("checked", value);
            }

            $(function () {

                <!-- this next block of functions are the select-all / clear-all behavior of the panel header checkbox -->
                <?php
                foreach ($this->instruments as $key => $instrument) {
                ?>
                $("#<?php echo $key ?>").click( function () {
                    var checked = $("#<?php echo $key ?>"); <!-- the header checkbox has the instrument name as its id -->
                    var checkBoxes = $(".<?php echo $key ?>"); <!-- the associated fields all have the instrument name as their class -->
                    checkBoxes.prop("checked", checked.prop("checked"));
                });
                <?php
                }
                ?>
                <!-- this data structure is passed in to bstreeview for rendering as the left side hierarchical list control -->
                var json = [
                    <?php
                    $first_time_through_inst = true;
                    foreach ($this->instruments as $key => $instrument) {
                    if (! $first_time_through_inst) { echo ",";}
                    $first_time_through_inst = false;

                    ?>
                    {
                        text: "<?php echo $instrument ?>",
                        id: "<?php echo $key ?>",
                        class: "draggable1 instrument",
                        icon: "fa fa-file-alt",
                        nodes: [
                            <?php
                            $fields = $module->getFieldNames($key);
                            $first_time_through_fields = true;
                            foreach ($fields as $field) {
                            if ($this->dataDict[$field]['field_type'] === 'descriptive') {
                                continue;
                            }
                            if (! $first_time_through_fields) { echo ",";}
                            $first_time_through_fields = false;
                            //error_log(print_r($dataDict[$field]['field_type'], TRUE));
                            ?>
                            {
                                text: "<?php echo $field ?>",
                                id: "<?php echo $field ?>",
                                class: "draggable1"
                            }
                            <?php
                            }
                            ?>
                        ]
                    }

                    <?php
                    }
                    ?>
                ];
                $('#tree').bstreeview({ data: JSON.stringify(json) });
                var item0 = $('#tree-item-0');
                item0.collapse();
                $('.state-icon', item0.previousSibling).slice(0,1)
                    .toggleClass('fa fa-angle-down')
                    .toggleClass('fa fa-angle-right')
                ;

                $( ".draggable1" ).draggable({
                    appendTo: "body",
                    helper: "clone",
                    opacity: 0.7
                });

                // separate out the drop handlers since we want different behaviors
                // this is the row filter drop target
                $( "#row_filter" ).droppable(
                    {
                        activeClass: "ui-state-default",
                        hoverClass: "ui-state-hover",
                        accept: ":not(.instrument)",
                        drop: function( event, ui )
                        {
                            $( "#tip_exporting_all_rows" ).remove();
                            // Copy the draggable into the destination box
                            var $copy =  ui.draggable.clone();
                            // console.log('copy is ');
                            // console.log($copy.html());
                            appendInputs($copy); // and call a REDCap API to decorate with suitable controls
                            $copy.appendTo(this);
                        }
                    });

                // this is the column specification target
                $( "#column_spec" ).droppable(
                    {
                        activeClass: "ui-state-default",
                        hoverClass: "ui-state-hover",
                        accept: ":not(.ui-sortable-helper)",
                        drop: function( event, ui )
                        {
                            $( "#tip_missing_col_1" ).remove();
                            $( "#tip_missing_col_2" ).remove();
                            // Copy the draggable so we don't modify it. Not strictly necessary in this case, as we're
                            // just showing/hiding existing elements as opposed to displaying the actual drop target
                            var copy =  ui.draggable.clone();
                            //  is this the instrument name or a field?
                            // if the instrument name, all fields are selected. If the field, only the field is selected
                            if (copy.attr('class').includes('instrument')) {
                                //  the user dropped the instrument name; check all the checkboxes in the panel
                                tickAllPanelCheckboxes (copy.text(), true);

                            } else {
                                var selector= "#" + copy.text();

                                var checkBoxes = $( selector );
                                checkBoxes.prop("checked", true)
                            }

                            panelName = getInstrumentForField(copy.text());
                            var panelSelector = "#panel-"+panelName;

                            $( panelSelector  ).show();
                            tagRepeatables();
                        }
                    }).sortable(
                    {
                        update: function(event, ui)
                        {
                            tagRepeatables();
                            // gets added unintentionally by droppable interacting with sortable
                            // using connectWithSortable fixes this, but doesn't allow you to customize active/hoverClass options
                            $( this ).removeClass( "ui-state-default" );
                        }
                    });

                $(document).on('click', '.delete-panel', function () {
                    $(this).closest('.panel').hide();
                    // the id of the outer panel is the instrument name prefixed with 'panel-' e.g. panel-person, panel-med etc
                    instrumentName = $(this).closest('.panel').attr('id').substr(6);
                    tickAllPanelCheckboxes (instrumentName, false);
                    $("#upper-bound-" + instrumentName).val("");
                    $("#lower-bound-" + instrumentName).val("");
                    tagRepeatables();
                });

                $(document).on('click', '.delete-criteria', function () {
                    $(this).closest('.list-group-item').remove();
                });

            });
        </script><?php
// start debug setup part 2
        $endtime = microtime(true);
        $timediff = $endtime - $this->starttime;
        $module->emDebug("instruments_and_fields completed in " . $module->secondsToTime($timediff));
// end debug setup part 2
    }
}