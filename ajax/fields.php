<?php

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */

use \REDCap;


try {
    if (!isset($_POST)) {
        throw new \LogicException('You cant be here');
    }

    $instrument = filter_var($_POST['key'], FILTER_SANITIZE_STRING);
    $primary = filter_var($_POST['primary'], FILTER_SANITIZE_STRING);
    $fields = REDCap::getFieldNames($instrument);
    if ($primary && $instrument) {
        ?>
        <div id="<?php echo $instrument ?>-fields" class="p-1" data-primary="<?php echo $primary ?>">
            <h5><?php echo $instrument ?></h5>
            <div style="border:1px solid #cecece;">
                <?php
                $counter = 0;
                foreach ($fields

                as $key => $field) {
                if ($counter % 7 == 0){
                if ($counter > 0){
                ?>
            </div>
            <?php
            }
            ?>
            <div class="row p-1">
                <?php
                $counter++;
                }
                ?>
                <div class="col-md-2">
                    <label for="<?php echo $field ?>_field">
                        <input style="vertical-align:middle;" checked type="checkbox" id="<?php echo $field ?>_field"
                               name="<?php echo $field ?>_field">&nbsp;&nbsp;<span
                                style="word-break: break-all"><?php echo $field ?></span>
                    </label>
                </div>
                <?php
                $counter++;
                }
                ?>
            </div>
        </div>
        <?php
    } else {
        throw new \LogicException('not instrument passed');
    }

} catch (\LogicException $e) {
    echo $e->getMessage();
}
?>