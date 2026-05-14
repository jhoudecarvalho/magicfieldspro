jQuery(function($)
{
	if (typeof window.mySettings === "undefined") {
		if (window.console && console.warn) {
			console.warn("Magic Fields: markItUp settings not loaded.");
		}
		return;
	}

	$(".markdowntextboxinterface").markItUp(window.mySettings); 
});
