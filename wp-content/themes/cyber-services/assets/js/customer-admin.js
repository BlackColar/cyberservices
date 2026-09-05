(function ($) {
  "use strict";

  $(function () {
    var root = $("[data-cyber-customer-logo]");
    if (!root.length || typeof wp === "undefined" || !wp.media) return;

    var frame;
    root.on("click", "[data-logo-select]", function () {
      if (!frame) {
        frame = wp.media({
          title: cyberCustomerAdmin.title,
          button: { text: cyberCustomerAdmin.button },
          library: { type: "image" },
          multiple: false,
        });
        frame.on("select", function () {
          var image = frame.state().get("selection").first().toJSON();
          var preview = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
          root.find("[data-logo-id]").val(image.id);
          root.find("[data-logo-remove]").val("0");
          root.find("[data-logo-preview]").html($("<img>", { src: preview, alt: "" }).css({ maxWidth: "100%", maxHeight: "68px" }));
        });
      }
      frame.open();
    });

    root.on("click", "[data-logo-delete]", function () {
      root.find("[data-logo-id]").val("0");
      root.find("[data-logo-remove]").val("1");
      root.find("[data-logo-preview]").empty();
    });
  });
})(jQuery);
