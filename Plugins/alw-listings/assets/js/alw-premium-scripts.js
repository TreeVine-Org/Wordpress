/*
 * Assisted Living Works - Premium Form Scripts
 *
 * Handles the WordPress Media Uploader for the premium listing form's image gallery.
 *
 * @version 0.8.0
 */
jQuery(document).ready(function ($) {

    // Frame for the media uploader. We define it once to reuse it.
    let mediaUploader;

    // When the "Select Images" button is clicked
    $('#alw-upload-gallery-button').on('click', function (e) {
        e.preventDefault();

        // If the uploader frame already exists, just open it
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        // Create the media uploader frame.
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Select Images for Your Gallery',
            button: {
                text: 'Add to Gallery'
            },
            multiple: true // Set to true to allow multiple image selection
        });

        // When images are selected, run this callback
        mediaUploader.on('select', function () {
            const attachments = mediaUploader.state().get('selection').toJSON();
            const galleryContainer = $('#alw-gallery-container');
            const galleryIdsInput = $('#alw_gallery_ids');
            let attachmentIds = [];

            // Clear the previous preview
            galleryContainer.html('');

            // Limit to 6 images, even if more are selected
            const limit = Math.min(attachments.length, 6);

            for (let i = 0; i < limit; i++) {
                const attachment = attachments[i];
                // Add the image ID to our array
                attachmentIds.push(attachment.id);

                // Create a thumbnail image and append it to the container
                // Use the 'thumbnail' size which is standard in WordPress
                if (attachment.sizes && attachment.sizes.thumbnail) {
                    galleryContainer.append('<img src="' + attachment.sizes.thumbnail.url + '" style="max-width:100px; height:auto; margin:5px;">');
                } else {
                    // Fallback for non-image files or if thumbnail size is missing
                    galleryContainer.append('<img src="' + attachment.url + '" style="max-width:100px; height:auto; margin:5px;">');
                }
            }

            // Populate the hidden input field with the comma-separated list of IDs
            galleryIdsInput.val(attachmentIds.join(','));
        });

        // Finally, open the uploader
        mediaUploader.open();
    });

});