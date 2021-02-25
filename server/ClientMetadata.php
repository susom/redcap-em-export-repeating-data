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
    private function valueOfActionTag($actionTag, $allTags) {
        $annotation = $allTags;

        // there are multiple action tags associated with a given form field
        $elements = explode('@', $allTags );
        foreach ($elements as $element) {
            if (contains($element,$actionTag)) {
                $annotation = $element;
            }
        }
        // pick out the value from this action tag
        $components = explode('=', $annotation );
        // $components[0] is the action tag, and $components[1] is the value we want
        return trim($components[1]," \n\r\t\v\0'\"");
    }
    function getClientMetadata() {
        global $module;
        // start debug setup part 1
// microtime(true) returns the unix timestamp plus milliseconds as a float
        $this->starttime = microtime(true);
        $module->emDebug("getClientMetadata launching");
// end debug setup part 1
        $lookupTable = array();
        $this->dataDict = $module->getDataDictionary();
        foreach ($this->dataDict as $key => $ddEntry) {
        if (contains($ddEntry['misc'], '@FORMINSTANCE')) {
                $parent_instrument = $this->valueOfActionTag('FORMINSTANCE', $ddEntry['misc']);
                $lookupTable[$ddEntry['form_name']] = $parent_instrument;
            }
        }
        $this->instruments = $module->getInstrumentNames();
        header("content-type: application/html");?>
        <script>

            var instrumentLookup;
            function getInstrumentForField(fieldOrInstrumentName) {
                // need to support getInstrumentForField(firstRepeatingPanel + '_@date_field'); for child linked fields
                // e.g. getInstrumentForField(meds_@date_field) should return visit_date
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
                            instrumentLookup["<?php echo $field ?>@validation"] = "<?php echo $module->getValue($field . '@validation') ?>";
                            instrumentLookup["<?php echo $field ?>@lov"] = "<?php echo $module->getValue($field . '@lov') ?>";
                        <?php
                        }
                        // last but not least, add lookups for parent instruments to handle the case where the relationships
                        // are implied by filters
                        ?>
                        instrumentLookup["<?php echo $instrument ?>@parent"] = "<?php echo $lookupTable[$instrument]?>";
                        <?php
                    }
                    ?>

                 }

                return instrumentLookup[fieldOrInstrumentName];
            }

            function  appendInputs(element, parent, restore, settings) {
                var fieldname = element.html();
                $.ajax({
                    url: $("#base-url").val(),
                    data: {field_name: fieldname, redcap_csrf_token: $("#redcap_csrf_token").val()},
                    type: 'POST',
                    success: function (data) {
                        data = '<input type="hidden" name="field_name" value="'+fieldname+'"/>'
                            + addAutoCompleteIfTextInput(data, fieldname) + appendFieldFilterControls();
                        element.append(data);
                        element.appendTo(parent);
                        // add exists or not-exists filters for all fields
                        let limiterOperator = element.find('.limiter-operator');
                        limiterOperator.append('<option value="EXISTS">exists</option>');
                        limiterOperator.append('<option value="NOT_EXIST">does not exist</option>');
                        // add last or earliest filter for date fields
                        if (data.indexOf('class="date_') > -1) {
                            limiterOperator.append('<option class="minmax" value="MAX">latest</option>');
                            limiterOperator.append('<option class="minmax" value="MIN">earliest</option>');;
                        }
                        // hide the text box if date filter is exists, not-exists, max or min
                        limiterOperator.attr('id', fieldname + '_op');
                        $('#' + fieldname +'_op').change(function() {
                            if ($( '#' + fieldname +'_op' + " option:selected").val() =='MAX' ||
                                $( '#' + fieldname +'_op' + " option:selected").val() =='MIN' ||
                                $( '#' + fieldname +'_op' + " option:selected").val() =='EXISTS' ||
                                $( '#' + fieldname +'_op' + " option:selected").val() =='NOT_EXIST') {
                                $('#' + fieldname +'_ac').val(''); // text box for free-text variables
                                $('#' + fieldname +'_ac').hide(); // text box for free-text variables
                                element.find('.limiter-value').val(''); // select list for structured variables
                                element.find('.limiter-value').hide(); // select list for structured variables
                            } else {
                                $('#' + fieldname +'_ac').show();// text box for free-text variables
                                element.find('.limiter-value').show(); // select list for structured variables
                            }
                        })
                        if (restore) {
                            element.find('.limiter-operator').val(settings.operator);
                            element.find('.limiter-value').val(settings.param);
                            element.find('select[name^="limiter_connector"]').val(settings.boolean);
                        }

                    },
                    error: function (request, error) {
                        alert("Request: " + JSON.stringify(request));
                    }
                });
            }

            function addAutoCompleteIfTextInput(data, fieldname1) {
                var ind = data.indexOf('type="text"');

                var newdata;
                if (ind > 0) {
                    var js = '\<script\>\$( function() {$( \"#'+fieldname1+'_ac\" ).autocomplete({source: '+fieldname1+'_aclov }); } );</script\>';

                    newdata = data.substr(0,ind) + ' id=\"' + fieldname1 + '_ac\" ' + data.substr(ind) +js  ;
                } else {
                    newdata = data;
                }

                return newdata;
            }

            function tagRepeatables() {
                // use sort order to specify the status of repeatable forms
                // scan the list for an instantiation of the referenced table
                // only use tier-2 if the referenced table is present in the list
                // otherwise use tier-3

                var isFirstRepeatingPanel = true;
                var targetDate;
                var repeatingForms = [];
                var firstRepeatingPanel;
                // set the appropriate classes for panel heading coloring and show/hide the correct badge
                $(".panel:visible").each(function() {
                    instrumentName= $(this).attr('id').substr(6);
                    if ($( this ).find(".repeating-primary").length !== 0) {
                        repeatingForms.push(instrumentName);
                        if (isFirstRepeatingPanel) {
                            // hide secondary badge and show primary badge
                            $(this).find(".repeating-primary").show();
                            $(this).find(".repeating-secondary").hide();
                            $(this).find(".repeating-tertiary").hide();
                            $(this).find(".panel-heading").addClass('tier-1');
                            $(this).find(".panel-heading").removeClass('tier-2');
                            $(this).find(".panel-heading").removeClass('tier-3');
                            isFirstRepeatingPanel = false;
                            firstRepeatingPanel = instrumentName;
                        } else {
                            $(this).find(".repeating-primary").hide();
                            var secondaryHeader = $(this).find(".repeating-secondary");
                            var tertiaryHeader = $(this).find(".repeating-tertiary");
                            secondaryHeader.show();
                            var panelHeading = $(this).find(".panel-heading");
                            panelHeading.removeClass('tier-1');
                            if (panelHeading.hasClass('ref-tier-2')) {
                                // look for the instance linked panel; only tag as tier-3 if present
                                var badge = $(this).find(".badge-primary");
                                var linkedToInstrument =  badge.text().substr(22).trim();
                                // console.log(instrumentName + ' linked to '+ linkedToInstrument);
                                targetDate = getInstrumentForField(firstRepeatingPanel + '_@date_field');
                                if (! targetDate) {
                                    targetDate = getInstrumentForField(getInstrumentForField(firstRepeatingPanel + '@parent') + '_@date_field');
                                }
                                $(this).find(".target-date").replaceWith("<span class='target-date'> after " + targetDate + " (days)</span>");
                                linkedInstrumentFound  = false;
                                for (let i = 0; i < repeatingForms.length; i++) {
                                    linkedInstrumentFound = linkedInstrumentFound || linkedToInstrument.includes( repeatingForms[i] );
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
                    '</button>';
            }

            function tickAllPanelCheckboxes (label, value) {
                var selector1 = "." + getInstrumentForField(label);
                var selector2 = "#" + getInstrumentForField(label) + "_cb";

                var checkBoxes = $( selector1 );
                checkBoxes.prop("checked", value);
                checkBoxes = $( selector2 );
                checkBoxes.prop("checked", value);
            }

            $(function () {

                // this next block of functions are the select-all / clear-all behavior of the panel header checkbox
                <?php
                foreach ($this->instruments as $key => $instrument) {
                ?>
                $("#<?php echo $key ?>_cb").click( function () {
                    var checked = $("#<?php echo $key ?>_cb"); // the header checkbox has the instrument name as its id
                    var checkBoxes = $(".<?php echo $key ?>"); // the associated fields all have the instrument name as their class
                    checkBoxes.prop("checked", checked.prop("checked"));
                });
                <?php
                }
                ?>
                // this data structure is passed in to bstreeview for rendering as the left side hierarchical list control
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
                                if ($this->dataDict[$field]['element_type'] === 'descriptive') {
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
                        //accept: ":not(.instrument), :not(.ui-sortable-handle)",
                        accept: function(draggable) {    // SRINI Should not accept, if it is from the sortable div also
                            if (draggable.hasClass("instrument") || draggable.hasClass("ui-sortable-handle"))
                                return false ;
                            else
                                return true ;
                            //if ($(this).)
                        },
                        drop: function( event, ui )
                        {
                            $( "#tip_exporting_all_rows" ).remove();
                            // Copy the draggable into the destination box
                            var copy =  ui.draggable.clone();
                            // console.log('copy is ');
                            // console.log($copy.html());

                            appendInputs(copy, this, false, null); // and call a REDCap API to decorate with suitable controls

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
                                checkBoxes.prop("checked", true);
                            }

                            panelName = getInstrumentForField(copy.text());
                            var panelSelector = "#panel-"+panelName;

                            $( panelSelector  ).show();
                            tagRepeatables();

                            // SRINI - SDM-135 - following is addded to avoid sortable issues
                            // when closing and opening the div
                            $( "#column_spec" ).sortable( "refreshPositions" );

                        }
                    }).sortable(
                    {
                        tolerance : "pointer",
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

                    // SRINI - SDM-135 - following is addded to avoid sortable issues
                    // when closing and opening the div
                    $( "#column_spec" ).sortable( "refreshPositions" );

                });

                $(document).on('click', '.delete-criteria', function () {
                    $(this).closest('.list-group-item').remove();
                });

            });
        </script><?php
// start debug setup part 2
        $endtime = microtime(true);
        $timediff = $endtime - $this->starttime;
        $module->emDebug("getClientMetadata completed in " . $module->secondsToTime($timediff));
// end debug setup part 2
    }

    function getFilterDefns() {
        global $module;
        $sql = "select rd.field_name,value
                        from redcap_data rd
                        join redcap_metadata rm on rd.project_id = rm.project_id
                         and rm.field_name = rd.field_name
                        where rd.project_id = ".$module->getProjectId()."
                        and element_type in ('calc', 'text')
                        group by rd.field_name, value
                        order by rd.field_name, upper(value)";
        $autodata = db_query($sql);
        $textFieldNames = [];

        $currentFieldName = "";
        if (strlen(db_error()) > 0) {
            $dberr = db_error();
            $module->emError('Query error in autocomplete generation: ' . print_r($dberr, TRUE));
         } else {
            echo "<script>";
            while ($row = db_fetch_assoc($autodata)) {
                $fieldName = $row['field_name'];
                $value= str_replace("\n","", $row['value']);
                if ($currentFieldName != $fieldName) {
                    if (strlen($currentFieldName) > 0) { // close off the end of the earlier variable definition
                        echo "\n];\n$( \"#$currentFieldName"."_ac\" ).autocomplete({\n  source: $currentFieldName"."_aclov\n});";
                    }
                    echo "\nvar $fieldName" . "_aclov = [";
                    $currentFieldName = $fieldName;
                    $textFieldNames[] = $currentFieldName;
                }
                echo "  '$value',";
                //$module->emDebug('merged: ' . print_r($data, TRUE));
            }
        }
        // $module->emDebug('YO1: ' . print_r($textFieldNames, TRUE));
        echo "\n];\n$( '#$currentFieldName"."_ac' ).autocomplete({\n  source: $currentFieldName"."_aclov\n});</script>";

        // ok, now the script has been written, add the field definitions
        foreach ($textFieldNames as $textFieldName) {
            //$module->emDebug('YO2: ' . print_r($textFieldName, TRUE));
            ?>
    <div id="row_filter" class="list-group filters-fields ui-droppable" style="min-height: 50px; width: 100%; display: none;">
    <div href="#tree-item-2" class="list-group-item draggable1 ui-draggable ui-draggable-handle" data-toggle="collapse" style="padding-left:2.5rem;"><?php echo $textFieldName?><input type="hidden" name="field_name" value="<?php echo $textFieldName?>"><select class="x-form-text x-form-field limiter-operator" name="limiter_operator[]">
	<option value="E">=</option>
	<option value="NE">not =</option>
            <option value="CONTAINS">contains</option>
            <option value="NOT_CONTAIN">does not contain</option>
	<option value="STARTS_WITH">starts with</option>
	<option value="ENDS_WITH">ends with</option>
            <option value="EXISTS">exists</option>
            <option value="NOT_EXIST">does not exist</option>
</select>
<input name="limiter_value[]" onblur="" class=" x-form-text x-form-field limiter-value" maxlength="255" style="max-width:150px;" value="" id="<?php echo $textFieldName?>_ac" type="text">
<select name="limiter_connector[]"><option value="AND">AND</option><option value="OR">OR</option></select><button type="button" class="delete-criteria close" aria-label="Close">
  <span aria-hidden="true">×</span>
</button></div></div>
<?php
        }

    }


}
