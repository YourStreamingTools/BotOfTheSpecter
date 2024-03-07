$(document).ready(function() {
  $("#show-api-key").click(function() {
    console.log("Show API key clicked");
    $(".api-key-wrapper").show();
    $("#show-api-key").hide();
    $("#hide-api-key").show();
  });

  $("#hide-api-key").click(function() {
    console.log("Hide API key clicked");
    $(".api-key-wrapper").hide();
    $("#show-api-key").show();
    $("#hide-api-key").hide();
  });
});