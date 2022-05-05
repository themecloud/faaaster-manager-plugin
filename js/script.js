(function ($) {
  $(document).ready(function () {
    $("#togglePlugin").click(function () {
      $.ajax({
        url:
          hostmanager.url +
          "?rest_route=/public-hostmanager/v1/toggle_mu_plugin",
        type: "GET",
        beforeSend: function (xhr) {
          xhr.setRequestHeader("X-WP-Nonce", hostmanager.nonce);
          xhr.setRequestHeader("X-TC-Token", hostmanager.tc_token);
        },
        data: {},
      }).done(function (response) {
        alert(response.data);
      });
    });
  });
})(jQuery);
