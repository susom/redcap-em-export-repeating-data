<?php
// this php script generates Javascript specific to this project
// to see what is produced, view source on the rendered page

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


// something is taking a while to load. is this it?
// start debug setup part 1
// microtime(true) returns the unix timestamp plus milliseconds as a float
$starttime = microtime(true);
$module->emDebug("instruments_and_fields launching");
// end debug setup part 1

$dataDict = $module->getDataDictionary();
$instruments = $module->getInstrumentNames();
$event = $module->getFirstEventId();
?>
<script>
    $(function () {

        <!-- this next block of functions are the select-all / clear-all behavior of the panel header checkbox -->
        <?php
        foreach ($instruments as $key => $instrument) {
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
            foreach ($instruments as $key => $instrument) {
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
                    if ($dataDict[$field]['field_type'] === 'descriptive') {
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
    });
</script>
<?php
// start debug setup part 2
$endtime = microtime(true);
$timediff = $endtime - $starttime;
$module->emDebug("instruments_and_fields completed in " . $module->secondsToTime($timediff));
// end debug setup part 2

?>