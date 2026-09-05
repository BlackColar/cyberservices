(function ($) {
  "use strict";

  $(function () {
    var root = $("[data-cyber-certification-image]");
    if (!root.length || typeof wp === "undefined" || !wp.media) return;

    var frame;
    root.on("click", "[data-image-select]", function () {
      if (!frame) {
        frame = wp.media({
          title: cyberCertificationAdmin.title,
          button: { text: cyberCertificationAdmin.button },
          library: { type: "image" },
          multiple: false,
        });
        frame.on("select", function () {
          var image = frame.state().get("selection").first().toJSON();
          var preview = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
          root.find("[data-image-id]").val(image.id);
          root.find("[data-image-remove]").val("0");
          root.find("[data-image-preview]").html($('<img>', { src: preview, alt: "" }).css({ maxWidth: "100%", maxHeight: "160px", objectFit: "contain" }));
        });
      }
      frame.open();
    });

    root.on("click", "[data-image-delete]", function () {
      root.find("[data-image-id]").val("0");
      root.find("[data-image-remove]").val("1");
      root.find("[data-image-preview]").empty();
    });
  });
})(jQuery);
