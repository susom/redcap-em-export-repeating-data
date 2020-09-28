
$(function()
{
   console.log('start draggable selector ');
   console.log($( ".draggable1" ));
    console.log('end draggable selector ');
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
                // Copy the draggable into the destination box
                var $copy =  ui.draggable.clone();
                //  is this the instrument name or a field?
                // if the instrument name, all fields are selected. If the field, only the field is selected
                if ($copy.attr('class').includes('instrument')) {
                    console.log('yes');
                } else {
                    console.log('no');
                }
                $copy.appendTo(this);


                console.log(this);
                // Debug
            //    console.log('item ' + itemName + ' (id: ' + itemIdMoved + ') dropped into ' + destinationId);
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

function showPanel(instrumentName) {

}

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
