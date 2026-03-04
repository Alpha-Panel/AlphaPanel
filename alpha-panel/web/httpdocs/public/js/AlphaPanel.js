function randString(){
    let possible = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789![]{}()%&*$#^<>~@|';
    let text = '';
    for(let i=0; i < 32; i++) {
        text += possible.charAt(Math.floor(Math.random() * possible.length));
    }
    return text;
}

$(document).ready(function() {
    $(".generate_password").on('click', function(){
        let fields = $('input[rel="gp"]');
        let password = randString();
        $.each(fields, function(index, value){
            let field_id = '#' + $(value).attr('id');
            $(field_id).val(password);
        });
    });

    // Auto Select Pass On Focus
    $('input[rel="gp"]').on("click", function () {
        $(this).select();
    });
});
