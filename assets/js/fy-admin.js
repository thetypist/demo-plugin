if (typeof($j) === 'undefined'){

    var $j = jQuery.noConflict();

}

const showAdminLoading = () => {
    $j("body").append("<div class='adminLoading'><span>Loading...</span></div>");
}

const hideAdminLoading = () => {
    $j(".adminLoading").remove();
}

$j(document).ready(function() {

    // Reset data
    $j(document).on('click touch', '.resetData', function(e){
        e.preventDefault();

        let ts = $j(this);
        let t  = $j(this).attr('data-target');
        let w = $j(this).attr('data-wpnonce');

        let d = "action=fyResetData&_wpnonce=" + w + "&target=" + t;

        $j.ajax({
            url: _fyAdminUrl,
            data: d,
            dataType: 'json',
            method: 'POST',
            beforeSend: function(){

                console.log(d);

                if ( !confirm( "Are you sure to reset?" ) ) {
                    return false;
                }

                ts.attr('disabled', 'disabled');

                showAdminLoading();

            },
            success: function(r){

                console.log(r);

                hideAdminLoading();

                ts.removeAttr('disabled');
                ts.removeProp('disabled');

                $j('.adminShowResults').html(r.data).show();

            }
        })

    })

    // Admin Form Settings
    $j(document).on('submit', '.freyAdminSettings', function(e){
        e.preventDefault();
        e.stopPropagation();

        let d = $j(this).serialize();

        $j.ajax({
            url: _fyAdminUrl,
            data: d,
            dataType: 'json',
            method: 'POST',
            beforeSend: function(){
                console.log(d);
                showAdminLoading();
            },
            success: function(r){
                console.log(r);
                hideAdminLoading();
            },
        })
    })


})