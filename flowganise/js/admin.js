jQuery(document).ready(function ($) {
  // Log version info to help with debugging
  if (typeof flowganiseAdmin !== "undefined" && flowganiseAdmin.version) {
    console.log("Flowganise Admin JS - Version: " + flowganiseAdmin.version);
  }

  $("#flowganise-connect").on("click", function () {
    const $button = $(this);
    const $status = $("#flowganise-connect-status");

    $button.prop("disabled", true);
    $status.html(
      '<div class="notice notice-info"><p>Redirecting to Flowganise...</p></div>'
    );

    // Store that we're connecting for when we return
    localStorage.setItem("flowganise_connecting", "true");

    // Make sure flowganiseUrl is defined and log all available properties in flowganiseAdmin for debugging
    console.log("flowganiseAdmin object:", flowganiseAdmin);

    // Default URL if flowganiseUrl is not defined
    const baseUrl =
      flowganiseAdmin.flowganiseUrl || "https://flowganise.com";

    // Properly construct the authorization URL using absolute URLs
    const authUrl =
      baseUrl +
      "/oauth/wordpress/authorize?" +
      "callback=" +
      encodeURIComponent(window.location.href);

    console.log("Redirecting to auth URL:", authUrl);

    // Redirect to authorization page
    window.location.href = authUrl;
  });

  $("#flowganise-disconnect").on("click", function () {
    if (confirm("Are you sure you want to disconnect from Flowganise?")) {
      const $button = $(this);
      $button.prop("disabled", true);

      // Simple disconnect by removing settings and reloading
      $.post(
        flowganiseAdmin.ajaxUrl,
        {
          action: "flowganise_disconnect",
          _ajax_nonce: flowganiseAdmin.nonce,
        },
        function () {
          window.location.reload();
        }
      );
    }
  });

  // Check if we're returning from the authorization flow
  if (localStorage.getItem("flowganise_connecting") === "true") {
    localStorage.removeItem("flowganise_connecting");

    // Get the site_id and api_key from the URL query parameters
    const urlParams = new URLSearchParams(window.location.search);
    const site_id = urlParams.get("site_id");
    const api_key = urlParams.get("api_key");

    if (!site_id) {
      $("#flowganise-connect-status").html(
        '<div class="notice notice-error"><p>Authorization failed: No site ID received</p></div>'
      );
      $("#flowganise-connect").prop("disabled", false);
      return;
    }

    // Show connecting status
    $("#flowganise-connect-status").html(
      '<div class="notice notice-info"><p>Finalizing connection...</p></div>'
    );

    console.log("Flowganise: Saving settings - site_id:", site_id, "api_key:", api_key);

    // Save connection settings directly (api_key comes from URL)
    $.post(
      flowganiseAdmin.ajaxUrl,
      {
        action: "flowganise_save_settings",
        _ajax_nonce: flowganiseAdmin.nonce,
        site_id: site_id,
        api_key: api_key || "",
        domain: window.location.origin,
      }
    )
      .then(function (response) {
        if (response.success) {
          $("#flowganise-connect-status").html(
            '<div class="notice notice-success"><p>Successfully connected with Flowganise!</p></div>'
          );
          // Reload without query params to clean up URL
          setTimeout(function () {
            window.location.href = window.location.pathname + "?page=flowganise-settings";
          }, 500);
        } else {
          $("#flowganise-connect-status").html(
            '<div class="notice notice-error"><p>Error saving settings: ' +
              (response.data || "Unknown error") +
              "</p></div>"
          );
          $("#flowganise-connect").prop("disabled", false);
        }
      })
      .catch(function (err) {
        console.error("Flowganise connection error:", err);
        $("#flowganise-connect-status").html(
          '<div class="notice notice-error"><p>Error connecting: ' +
            err.message +
            "</p></div>"
        );
        $("#flowganise-connect").prop("disabled", false);
      });
  }
});