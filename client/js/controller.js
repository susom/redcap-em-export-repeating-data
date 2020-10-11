$(function () {

    var struct = {};
    // launch a callback to the server to render the javascript used
    // to display the left hand navigation, since this seems to take a long time
    $.ajax({
        url: $("#clientmeta-submit").val(),
        timeout: 60000000,
        type: 'GET',
        data: struct,
        dataType: 'html',
        success: function (response) {
            $("#ui-loading").hide();
            if (response.status === 0) {
                showError("Error: " + response.message);
            } else {
                $("#insert-js-here").replaceWith(response);
            }

        },
        error: function (request, error) {
            $("#ui-loading").hide();
            showError("STARTUP Server Error: " + JSON.stringify(error));
            // console.log(request);
            console.log(error);
        }
    });
    // attach a drag n drop file upload handler for UI settings restore from save file
    $('#holder').on({
        'dragover dragenter': function(e) {
            e.preventDefault();
            e.stopPropagation();
        },
        'drop': function(e) {
            //console.log(e.originalEvent instanceof DragEvent);
            var dataTransfer =  e.originalEvent.dataTransfer;
            if( dataTransfer && dataTransfer.files.length) {
                e.preventDefault();
                e.stopPropagation();
                $.each( dataTransfer.files, function(i, file) {
                    var reader = new FileReader();
                    reader.onload = $.proxy(function(file, $fileList, event) {
                        try {
                            clearError();
                            applyModel(JSON.parse(reader.result));
                        } catch(e) {
                            console.log(e);
                            showError("Unrecognized file type. To restore settings, select a file previously saved by clicking 'Save Settings'");
                        }
                    }, this, file, $("#fileList"));
                    reader.readAsText(file);

                });
            }
            $("#dialog").dialog('close');
        }
    });

    // last but not least, trigger a round trip to the server asking for the record count
    runQuery(false, true);
});

function applyModel(model) {
    $("#row_filter").find(".list-group-item").remove();
    $(".panel").hide();
    if( !model.hasOwnProperty('reportname')) {
        showError("Unrecognized file type. To restore settings, please select a file previously saved by clicking 'Save Settings'");
        return;
    }
    $("#report_name").val(model.reportname)
    values = model['columns'];
    if (values.length > 0) {
        $( "#tip_missing_col_1" ).remove();
        $( "#tip_missing_col_2" ).remove();

    }
    for (var i = 0; i < values.length; i++) {
        $("#panel-" + values[i].instrument).show();
        $("#" + values[i].instrument).prop("checked", true);
        $("#" + values[i].field).prop("checked", true);
    }
    tagRepeatables();
    values = model['filters'];
    if (values.length > 0) {
        $( "#tip_exporting_all_rows" ).remove();
    }
    for ( i=0; i < values.length; i++) {
        var copy = $('<div class="list-group-item" style="padding-left:2.5rem;">' + values[i].field + '</div>');
        appendInputs(copy, $( "#row_filter" ), true, values[i]);
    }
}

function applyValdtn(uiElement) {
    // seems to be required by REDCap in the filter fields
    return true;
}

function runQuery(preview, record_count) {
    // when preview === false, a CSV w/ headers comes back for data download;
    // when preview === true, 200 rows without a header is returned for display inline in the datatable
    // when record_count === false, data is returned as described above (csv / json)
    // when record_count === true, a single integer is returned
    var formdata = $("#export-repeating").serializeArray();

    if (configurationError(formdata)) {
        return;
    }

    var json = getExportJson(preview, formdata, record_count);
    clearError();
    if (json.columns.length === 0 && record_count === false) {
        showError("You must drag at least one field from the list on the left and drop it into the 'Specify Report Columns' box above. ");
        return;
    }
    $("#longop-running").show();
    $.ajax({
        url: $("#report-submit").val(),
        data: json,
        timeout: 60000000,
        type: 'POST',
        dataType: 'json',
        success: function (response) {
            $("#longop-running").hide();
            if (response.status === 0) {
                showError("Error: " + response.message);
            } else {
                if (preview) {
                    var table_data = tableize(response.headers, response.data);
                    // console.log(table_data);
                    $("#preview-table-div").replaceWith(table_data);
                    $('#preview-table').DataTable();
                    $("#datatable").show();
                } else if (record_count) {
                    // console.log (response);
                    var count = response.count;
                    $("#count-display").html( ' matching records: ' + count);
                } else {
                    var csv_data = convertToCSV(response.data);
                    triggerDownload(csv_data, json.reportname + ".csv", 'text/csv;charset=utf-8;' )
                }
            }

        },
        error: function (request, error) {
            $("#longop-running").hide();
            showError("Server Error: " + JSON.stringify(error));
        }
    });
}

function promptForUpload() {
    $( "#dialog" ).dialog({
        resizable: false,
        height: "auto",
        width: 430,
        modal: true
    });
    $( "#dialog" ).show();
}

function configurationError(formdata) {

    var errorFound = false;
    formdata.forEach(function (item, index) {
        if ( (item.name.startsWith('lower-bound') || item.name.startsWith('upper-bound') ) ) {
            if ( isNaN(item.value) === true) {
                showError("non-numeric characters found in input string '" + item.value);
                errorFound = true;
            }
        }
    });

    if ( $(".badge-danger:visible").length > 0) {
        showError("Please ask your REDCap administrator to configure this project with @PRINCIPAL_DATE " +
            "and/or @FORMINSTANCE action tags as per the <a href='https://github.com/susom/redcap-em-export-repeating-data'>documentation for this module</a>");
        errorFound = true;
    }
    return errorFound;
}

function clearError() {
    $( "#data-error" ).hide();
}

function showError(message) {
    $( "#datatable" ).hide();
    $("#data-error-message").replaceWith('<div id="data-error-message"  class="alert alert-danger mt-5" >' + message + '</div>');
    $( "#data-error" ).show();
}

function tableize(headers, rows) {
    // datatable.net seems to expect full on <table><tr><td></td></tr></table> markup
    var table = '<div id="preview-table-div"><table id="preview-table" class="display"><thead>';
    headers.forEach(function (header, index) {table +=  '<th>' + header + '</th>';});
    table += '</thead><tbody>'
    rows.forEach(function (row, index) {
        table += '<tr>'
        isFirst = true;
        row.forEach(function(cell, index) {
            if (isFirst) {
                table +=  '<td><a href="' + getInstrumentForField('url') + cell + '">' + cell + '</a></td>';
                isFirst = false;
            } else {
                table +=  '<td>' + cell + '</td>';
            }
        });
        table += '</tr>'
    });
    table += '</tbody></table></div>'
    return table;
}

function tableize_col(col, index) {
    return '<td>' + col + '</td>';
}

function saveExportJson() {
    var formdata = $("#export-repeating").serializeArray();
    var json = getExportJson(false, formdata, false);
    triggerDownload(JSON.stringify(json), json.reportname + ".json", 'text/json;charset=utf-8;' )
}

function getLabelOrCode(field, item_value, raw_or_label) {
    // used when assembling the model of the user-specified filters. If raw data, return the item as is
    // otherwise look up and return the associated label, so the filter value will match the selected data
    lov = getInstrumentForField(field + '@lov');
    if (raw_or_label === 'label' && lov !== "") {

        parts1 = lov.split("\n " + item_value + ", ");
        if (parts1.length === 1) {
            // they selected the first item on the list
            parts1 = lov.split(item_value + ", ");
        }
        parts2 = parts1[1].split("\n");
        return parts2[0].trim();
    }
    return item_value;
}

function getExportJson(is_preview, formdata, record_count) {
    var struct = {};
    struct.record_count = record_count;
    struct.reportname = 'unnamed_report';
    var columns =[];
    if (! record_count) {
        $('.column-selector').each(function () {
            if ($(this).prop('checked')) {
                var table = {};
                table.instrument = $(this).closest('.panel').attr('id').substr(6);
                table.field = $(this).prop('id');
                columns.push(table);
            }
        });
    }

    // ok, now that we have the column specification, build up the filters.
    // the inputs generated by the REDCap API call have a quirky naming convention
    // process the results in the order returned, looking for these four names:
    // field_name, limiter_operator[], limiter_value[], and limiter_connector[]
    // we get back an array of objects with name: and value: as the two properties
    // this is the target format:
    //        "filters": [{
    //                 "instrument": "person",
    //                 "field": "record_id",
    //                 "operator": "equals",
    //                 "param": "1",
    //                 "boolean": "AND"
    //             }

    var filters = [];
    var filter;
    var joins = [];
    var join;
    var cardinality = [];

    formdata.forEach(function (item, index) {

        if (item.name === 'field_name') {
            filter = {};
            filter.field = item.value;
        } else if (item.name === 'limiter_operator[]') {
            filter.operator = item.value;
        } else if (item.name === 'limiter_value[]') {
            filter.validation = getInstrumentForField(filter.field + '@validation');
            filter.param = getLabelOrCode(filter.field, item.value, struct.raw_or_label);
        } else if (item.name === 'limiter_connector[]') {
            filter.boolean = item.value;
            filter.instrument = getInstrumentForField(filter.field);
            filters.push(Object.assign({}, filter));
        } else if (item.name === 'report_name') {
            if (item.value.length > 0) {
                struct.reportname = item.value;
            }
        } else if (item.name.startsWith('lower-bound')) {
            join = {};
            join.instrument_name = item.name.substr(12);
            join.lower_bound = item.value;
        } else if (item.name.startsWith('upper-bound')) {
            join.upper_bound = item.value;
            joins[join.instrument_name] = (Object.assign({}, join));
        } else if (item.name.startsWith('raw_or_label')) {
            struct.raw_or_label = item.value;
        }
    });
    // now patch the filter spec so that the last filter in the list uses AND. When OR is specified, the SQL breaks
    var nFilters = filters.length;
    if (nFilters > 0) {
        filters[nFilters - 1].boolean = 'AND';
    }

    // assemble the list of visible panels, in order, with their status
    $(".panel:visible").each(function() {
        join = {};
        instrument_name= $(this).attr('id').substr(6);

        var panelHeading = $(this).find(".panel-heading");
        if (panelHeading.hasClass('tier-0')) {
            join.join = 'singleton'
        } else if (panelHeading.hasClass('tier-1')) {
            join.join = 'repeating-primary'
        } else if (panelHeading.hasClass('tier-2')) {
            join.join = 'repeating-instance-select'
        } else if (panelHeading.hasClass('tier-3')) {
            join.join = 'repeating-date-pivot'
            join.primary_date = getInstrumentForField(instrument_name + '_@date_field');
        }

        cardinality[instrument_name] = Object.assign({}, join);
        if (join.join !== 'singleton') {
            cardinality[instrument_name].upper_bound = joins[instrument_name].upper_bound;
            cardinality[instrument_name].lower_bound = joins[instrument_name].lower_bound;
        }
    });

    struct.preview = is_preview ;
    struct.project = "standard";
    struct.columns = columns;
    struct.filters = filters;
    struct.cardinality = Object.assign({}, cardinality);
    // console.log(struct);
    return struct;

}

function convertToCSV(objArray) {
    var array = typeof objArray != 'object' ? JSON.parse(objArray) : objArray;
    var str = '';

    for (var i = 0; i < array.length; i++) {
        var line = '';
        for (var index in array[i]) {
            if (line !== '') line += ','
            if (array[i][index] && array[i][index].includes(",")) {
                line += '"';
            }
            line += escape_doublequotes(array[i][index]);
            if (array[i][index] && array[i][index].includes(",")) {
                line += '"';
            }
        }

        str += line + '\r\n';
    }

    return str;
}

function escape_doublequotes(data) {
    if (!data) return data;
    return data.replace(/["]/g, '""');
}

function triggerDownload(data, filename, filetype) {
    var blob = new Blob([ data ], {type: filetype});
    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename );
    } else {
        var link = document.createElement("a");
        if (link.download !== undefined) { // feature detection
            // Browsers that support HTML5 download attribute
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename );
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
}


