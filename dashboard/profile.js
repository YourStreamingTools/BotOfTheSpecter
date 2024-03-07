$(document).ready(function() {
  $("#show-api-key").click(function() {
    console.log("Show API Key button clicked");
    console.log($(".api-key-wrapper").css("display"));
    $(".api-key-wrapper").show();
    console.log($(".api-key-wrapper").css("display"));
    $("#show-api-key").hide();
    $("#hide-api-key").show();
  });

  $("#hide-api-key").click(function() {
    console.log("Hide API Key button clicked");
    console.log($(".api-key-wrapper").css("display"));
    $(".api-key-wrapper").hide();
    console.log($(".api-key-wrapper").css("display"));
    $("#show-api-key").show();
    $("#hide-api-key").hide();
  });
});