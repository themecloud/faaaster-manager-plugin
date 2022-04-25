(function ($) {
  $(document).ready(function () {
    $("#togglePlugin").click(function () {
      $.ajax({
        url: hostmanager.url + "/wp-json/hostmanager/v1/toggle_plugin",
        type: "GET",
        data: {},
      }).done(function (response) {
        alert(response.data);
      });
    });
  });
})(jQuery);
