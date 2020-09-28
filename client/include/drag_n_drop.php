<?php
// this php script generates Javascript specific to this project

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
use \REDCap;
$instruments = REDCap::getInstrumentNames();

?>
<script>
    var instrumentLookup;
    function getInstrumentForField(fieldOrInstrumentName) {
        // console.log('fieldOrInstrumentName');
        // console.log(fieldOrInstrumentName);
        if (! instrumentLookup) {
            instrumentLookup = [];
            <?php
            foreach ($instruments as $key => $instrument) {
            ?>
            // this entry is used when a folder name is dropped into the columns panel
            instrumentLookup["<?php echo $instrument ?>"] = "<?php echo $key ?>";
            //  this idempotent entry is actually used, when de-selecting all checkbox values
            //    when a panel is hidden by clicking the x in the upper right
            instrumentLookup["<?php echo $key ?>"] = "<?php echo $key ?>";
            <?php
            $fields = REDCap::getFieldNames($key);
            foreach ($fields as $field) {
            ?>
            instrumentLookup["<?php echo $field ?>"] = "<?php echo $key ?>";
            <?php
            }
            }
            ?>
        }
        return instrumentLookup[fieldOrInstrumentName];
    }
    $(function()
    {

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
            }).sortable(
            {
                sort: function()
                {
                    // gets added unintentionally by droppable interacting with sortable
                    // using connectWithSortable fixes this, but doesn't allow you to customize active/hoverClass options
                    $( this ).removeClass( "ui-state-default" );
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
//                    console.log(copy.text());

                    panelName = getInstrumentForField(copy.text());
                    // console.log('panel name is...');
                    // console.log(panelName);

                    var panelSelector = "#panel-"+panelName;
                    // console.log('panelSelector');
                    // console.log(panelSelector);
                    $( panelSelector  ).show();

                }
            }).sortable(
            {
                sort: function()
                {
                    // gets added unintentionally by droppable interacting with sortable
                    // using connectWithSortable fixes this, but doesn't allow you to customize active/hoverClass options
                    $( this ).removeClass( "ui-state-default" );
                }
            });

        $(document).on('click', '.delete-panel', function () {
            $(this).closest('.panel').hide();
            // the id of the outer panel is the instrument name prefixed with 'panel-' e.g. panel-person, panel-med etc
            tickAllPanelCheckboxes ($(this).closest('.panel').attr('id').substr(6), false);
        });

        $(document).on('click', '.delete-criteria', function () {
            $(this).closest('.list-group-item').hide();
        });

        // ajax example, not currently in use
        $(".deploy_button").click(function() {
            $.ajax({
                beforeSend: function() {
                    $('#statusbox').html("Running deployment...");
                },
                type: "POST",
                url: "deploy.php",
                data: build_payload(),
                success: function() {
                    console.log('Qa-run OK');
                    //previously called via an onClick
                    poll_loop = setInterval(function() {
                        display_output("#statusbox", 'getoutput.php');
                    }, 2000);
                },
                error: function() {
                    console.log('Qa-run failed.');
                }
            });
        });

    });

    function  appendInputs(element) {

        $.ajax({
            url: $("#base-url").val(),
            data: {field_name: element.html(), redcap_csrf_token: $("#redcap_csrf_token").val()},
            type: 'POST',
            success: function (data) {
                data = ' ' + data + appendContactInput();
                element.append(data);

            },
            error: function (request, error) {
                alert("Request: " + JSON.stringify(request));
            }
        });
    }

    function appendContactInput  () {
        return '<select name="limiter_connector[]"><option value="AND">AND</option><option value="OR">OR</option></select><button type="button" class="delete-criteria close" aria-label="Close">\n' +
            '  <span aria-hidden="true">&times;</span>\n' +
            '</button>'
    }

    function tickAllPanelCheckboxes (label, value) {
        console.log ('tickAllPanelCheckboxes');
        console.log (label);
        var selector1 = "." + getInstrumentForField(label);
        console.log(selector1);
        var selector2 = "#" + getInstrumentForField(label);
        console.log(selector2);
        var checkBoxes = $( selector1 );
        checkBoxes.prop("checked", value);
        checkBoxes = $( selector2 );
        checkBoxes.prop("checked", value);
    }
</script>
