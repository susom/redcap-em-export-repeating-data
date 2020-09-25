<?php

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */

use \REDCap;

?>
<script>
    $(function () {

        var json = [
            <?php
            $dataDict = REDCap::getDataDictionary('array');

            $instruments = REDCap::getInstrumentNames();
            $event = $module->getFirstEventId();
            $first_time_through_inst = true;
            foreach ($instruments as $key => $instrument) {
            if (! $first_time_through_inst) { echo ",";}
            $first_time_through_inst = false;

            ?>
            {
                text: "<?php echo $instrument ?>",
                id: "<?php echo $key ?>",
                class: "draggable1 instrument",
                icon: "fa fa-folder",
                nodes: [
                        <?php
                        $fields = REDCap::getFieldNames($key);
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
console.log('in instruments_and_fields');
console.log(json);
        console.log('in instruments_and_fields');
        $('#tree').bstreeview({ data: JSON.stringify(json) });
        $('#tree-item-0').collapse();
    });
</script>
