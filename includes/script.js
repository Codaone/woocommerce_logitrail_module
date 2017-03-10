jQuery(document).ready(function($) {
    function clearDebugLog() {
		$.ajax({
			url: '/index.php/checkout/?wc-ajax=logitrail_debug_log_clear',
			method: 'post',
			success:function(data) {
                $('#logitrail-debug-log > div').html("<div style='line-height: 17px; width: 100%'>Log cleared.</div>");

                $('#logitrail-debug-log > div').append('<div style="width: 100%; text-align: center; line-height: 15px; padding-top: 10px;"><button style="margin-left: 15px;" disabled>Clear log</button> <button style="margin-left: 15px;" onClick="jQuery(\'#logitrail-debug-log\').remove();">Close</button></div>');
			},
			error: function(errorThrown){
				$('#logitrail-export-notify div').css('width', '350px');
				$('#logitrail-export-notify div').html('Error exporting products. Try again later.<button style="margin-left: 15px;" onClick="jQuery(\'#logitrail-export-notify\').remove();">Close</button>');
			}
		});
    }


	$('.export-now').click(function(e) {
		e.preventDefault();

		$('#wpwrap').append('<div id="logitrail-export-notify" style="opacity: 0.6; right: 0px; bottom; 0px; height: 100%; width: 100%; position: absolute; top: 0px; left: 0px; z-index: 10000; background-color: black;">\n\
								<div style="position: absolute; border-radius: 10px; height: 50px; width: 250px; background-color: #ffffff; left: 50%; top: 50%; margin-left: -125px; margin-top: -25px; text-align: center; line-height: 50px;">Exporting products, please wait.</div>\n\
							</div>');
		$.ajax({
			url: '/index.php/checkout/?wc-ajax=logitrail_export_products',
			method: 'post',
			success:function(data) {
				$('#logitrail-export-notify div').html('Products exported.&nbsp;<button style="margin-left: 15px;" onClick="jQuery(\'#logitrail-export-notify\').remove();">Close</button>');
				location.reload();
			},
			error: function(errorThrown){
				$('#logitrail-export-notify div').css('width', '350px');
				$('#logitrail-export-notify div').html('Error exporting products. Try again later.<button style="margin-left: 15px;" onClick="jQuery(\'#logitrail-export-notify\').remove();">Close</button>');
			}
		});
	});

	$('.debug-log').click(function(e) {
		e.preventDefault();

        $('#wpwrap').append('<div id="logitrail-debug-log" style="opacity: 0.6; right: 0px; bottom; 0px; height: 100%; width: 100%; position: absolute; top: 0px; left: 0px; z-index: 10000; background-color: black;">\n\
                <div style="position: absolute; opacity: 0.9; padding: 10px; border-radius: 10px; width: 550px; background-color: #ffffff; left: 50%; top: 50%; margin-left: -275px; margin-top: -25px; text-align: left; line-height: 50px;">Loading log, please wait.</div>\n\
            </div>');

		$.ajax({
			url: '/index.php/checkout/?wc-ajax=logitrail_debug_log',
			method: 'post',
			success:function(data) {
                $('#logitrail-debug-log div').html("");

                if(data.length > 0) {
                    data.forEach(function(line) {
                        $('#logitrail-debug-log > div').append('<div style="line-height: 17px; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #eaeaea;">' + line + '</div>');
                    });
                }
                else {
                    $('#logitrail-debug-log > div').append('<div style="line-height: 17px; width: 100%;">Log empty.</div>');
                }

                $('#logitrail-debug-log > div').append('<div style="width: 100%; text-align: center; line-height: 15px; padding-top: 10px;"><button style="margin-left: 15px;" id="logitrail-debug-log-clear">Clear log</button> <button style="margin-left: 15px;" onClick="jQuery(\'#logitrail-debug-log\').remove();">Close</button></div>');

                $('#logitrail-debug-log-clear').click(clearDebugLog);
			},
			error: function(errorThrown){
				$('#logitrail-export-notify div').css('width', '350px');
				$('#logitrail-export-notify div').html('Error exporting products. Try again later.<button style="margin-left: 15px;" onClick="jQuery(\'#logitrail-export-notify\').remove();">Close</button>');
			}
		});

    });
});