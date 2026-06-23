window.addEventListener("load", function(){

    setTimeout(function(){

        document.getElementById("preloader")
            .style.display = "none";

        document.getElementById("content")
            .classList.remove("hidden");

    }, 3000);

});