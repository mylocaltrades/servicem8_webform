(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.serviceM8Loader = {
    attach: function (context) {
      // Attach to ALL webform submission forms
      $('.webform-submission-form', context).each(function() {
        var $form = $(this);
        
        // Check if we've already attached to this specific form
        if ($form.data('servicem8-loader-attached')) {
          return;
        }
        
        // Mark this form as processed
        $form.data('servicem8-loader-attached', true);
        
        // Add submit handler
        $form.on('submit', function(e) {
          // Check if form has errors or loader already exists
          if ($('#servicem8-loader').length || $form.find('.error').length) {
            return;
          }
          
          // Create and show loading overlay
          var loader = '<div id="servicem8-loader" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;">' +
            '<div style="background:white;padding:40px;border-radius:10px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,0.1);">' +
              '<div style="width:50px;height:50px;border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div>' +
              '<h3 style="margin:20px 0 10px;color:#333;">Submitting your enquiry...</h3>' +
              '<p style="margin:0;color:#666;">Please wait while we process your request.<br>' +
              '<small>This usually takes 15-20 seconds.</small></p>' +
            '</div>' +
          '</div>' +
          '<style>@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>';
          
          $('body').append(loader);
        });
      });
    }
  };
})(jQuery, Drupal);