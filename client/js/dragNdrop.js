
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
            accept: ":not(.ui-sortable-helper)",
            drop: function( event, ui )
            {
                $( "#tip_exporting_all_rows" ).hide();
                // Copy the draggable into the destination box
                var $copy =  ui.draggable.clone();
                $copy.appendTo(this);
             //   "<p>Test</p>".appendTo(this);
//            this.append( "<p>Test</p>" );


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
    // this is the column specification target
    $( "#column_spec" ).droppable(
        {
            activeClass: "ui-state-default",
            hoverClass: "ui-state-hover",
            accept: ":not(.ui-sortable-helper)",
            drop: function( event, ui )
            {
                $( "#tip_missing_col_1" ).hide();
                $( "#tip_missing_col_2" ).hide();
                // Copy the draggable into the destination box
                var $copy =  ui.draggable.clone();
                $copy.appendTo(this);
                // on second thought, dont bother

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
});
