jQuery(document).ready(function($) {
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
			},
			error: function(errorThrown){
				$('#logitrail-export-notify div').css('width', '350px');
				$('#logitrail-export-notify div').html('Error exporting products. Try again later.<button style="margin-left: 15px;" onClick="jQuery(\'#logitrail-export-notify\').remove();">Close</button>');
			}
		});
	});

});