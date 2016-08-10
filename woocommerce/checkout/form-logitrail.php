<script src="https://connect.logitrail.com/logitrail.js"></script>

<div id="logitrailContainer" style=""></div>

<script>
jQuery( 'body' ).on( 'updated_checkout', doLogitrailCheckout);

var logitrailTimeout;

function doLogitrailCheckout() {
	// timeout for some manner of debounce, tweak time based on need
	clearTimeout(logitrailTimeout);

	logitrailTimeout = setTimeout(function() {
		Logitrail.checkout({
			containerId: 'logitrailContainer',
			bridgeUrl: '?wc-ajax=logitrail',
			<?php if($useTestServer) { ?>
			host: "http://checkout.test.logitrail.com",
			<?php } ?>
			success: function(logitrailResponse) {

                jQuery(document).ready(function($) {

                    $.ajax({
                        url: '/index.php/checkout/?wc-ajax=logitrail_setprice',
                        method: 'post',
                        data: {
							'postage': logitrailResponse.delivery_fee,
							'order_id': logitrailResponse.order_id
						},
						success:function(data) {
							// This outputs the result of the ajax request
							setTimeout(function (){
								jQuery('body').trigger('update_checkout');
							}, 2000);
						},
						error: function(errorThrown){
							console.log('e', errorThrown);
						}
					});

				});
			},
			error: function(error) {
				alert('Logitrail Error occurred.');
			}
		});
	}, 1250);
};

</script>
