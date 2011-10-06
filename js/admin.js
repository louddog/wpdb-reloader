jQuery(function($) {
	$('#db_reloader_switch').buttonset();
	
	$('#db_reloader_save_state_form').submit(function(event) {
		if (confirm("Are you sure? This will overwrite your previous state.")) {
			return true;
		} else {
			event.stopImmediatePropagation();
			return false;
		}
	});
	
	$('#db_reloader_settings form').submit(function() {
		$(':submit', this).val("Please wait");
	});
});