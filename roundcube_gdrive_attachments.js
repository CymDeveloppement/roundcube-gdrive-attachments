/**
 * Roundcube Google Drive Attachments
 *
 * Sends the files larger than the attachment limit to Google Drive and
 * inserts their download link in the message.
 *
 * @license MIT License: <http://opensource.org/licenses/MIT>
 */
window.rcmail && rcmail.addEventListener('init', function () {
    var config = rcmail.env.gdrive_attachments;

    if (!config || rcmail.env.action != 'compose') {
        return;
    }

    // Names of the attachments replaced by a Drive link
    var drive_files = {};

    // Roundcube 1.6 get_label() does not replace variables
    var label = function (name, vars) {
        var text = rcmail.get_label(name, 'roundcube_gdrive_attachments');

        $.each(vars || {}, function (key, value) {
            text = text.split('$' + key).join(value);
        });

        return text;
    };

    var file_size = function (files) {
        var size = 0;

        for (var i = 0; i < files.length; i++) {
            size += files[i].size;
        }

        return size;
    };

    var show_bytes = function (bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;

        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }

        return (i ? bytes.toFixed(1) : bytes) + ' ' + units[i];
    };

    var dialog = function (title, text, buttons) {
        var $dialog = rcmail.show_popup_dialog($('<p>').text(text), title, $.map(buttons, function (button) {
            return {
                text: button.text,
                'class': button['class'] || '',
                click: function () {
                    $dialog.dialog('close');
                    if (button.click) {
                        button.click();
                    }
                },
            };
        }));
    };

    var file_upload = rcmail.file_upload;

    // Sends the files to Drive: the core checks the size against
    // env.max_filesize, raised while the request is being built
    var drive_upload = function (files, post_args, props) {
        var max_filesize = rcmail.env.max_filesize, result;

        if (config.max_upload && file_size(files) > config.max_upload) {
            rcmail.display_message(label('file_too_big_for_server', { size: config.max_upload_text }), 'error');
            return false;
        }

        rcmail.env.max_filesize = 0;
        try {
            result = file_upload.call(rcmail, files, $.extend({ _target: 'gdrive' }, post_args || {}), props);
        } finally {
            rcmail.env.max_filesize = max_filesize;
        }

        return result;
    };

    rcmail.file_upload = function (files, post_args, props) {
        // Only the attachments of the message, not the files of other forms
        if (!files || !files.length || (props && props.action && props.action != 'upload')) {
            return file_upload.apply(rcmail, arguments);
        }

        files = Array.prototype.slice.call(files);

        var size = file_size(files),
            limit = rcmail.env.max_filesize,
            human_size = show_bytes(size),
            human_limit = show_bytes(limit);

        // Too big to be attached
        if (limit && size > limit) {
            if (config.behavior == 'upload') {
                return drive_upload(files, post_args, props);
            }

            dialog(label('file_too_big'), label('file_too_big_explain', { size: human_size, limit: human_limit }), [
                { text: label('upload_to_drive'), 'class': 'mainaction', click: function () { drive_upload(files, post_args, props); } },
                { text: rcmail.get_label('cancel'), 'class': 'cancel' },
            ]);

            return false;
        }

        // Could be attached, but a link is advised
        if (config.softlimit && size > config.softlimit) {
            if (config.behavior == 'upload') {
                return drive_upload(files, post_args, props);
            }

            dialog(label('file_big'), label('file_big_explain', { size: human_size }), [
                { text: label('upload_to_drive'), 'class': 'mainaction', click: function () { drive_upload(files, post_args, props); } },
                { text: label('attach_anyway'), click: function () { file_upload.call(rcmail, files, post_args, props); } },
            ]);

            return false;
        }

        return file_upload.apply(rcmail, arguments);
    };

    // Asks whether the file must also be removed from Drive
    var remove_attachment = rcmail.remove_attachment;

    rcmail.remove_attachment = function (name) {
        var attachment = name && rcmail.env.attachments[name];

        if (!attachment || !drive_files[attachment.name]) {
            return remove_attachment.apply(rcmail, arguments);
        }

        dialog(label('remove_title'), label('remove_question'), [
            {
                text: label('remove_from_drive'),
                'class': 'mainaction delete',
                click: function () {
                    rcmail.http_post('remove-attachment', { _id: rcmail.env.compose_id, _file: name, _gdrive_remove: 1 });
                },
            },
            { text: label('keep_on_drive'), click: function () { remove_attachment.call(rcmail, name); } },
        ]);

        return false;
    };

    // The server uploaded a file: insert its link before the signature
    rcmail.addEventListener('plugin.gdrive_attachments.uploaded', function (file) {
        drive_files[file.attachment] = true;

        var editor = rcmail.editor,
            expires = file.expires_text ? label('available_until', { date: file.expires_text }) : '';

        if (editor.is_html()) {
            var tinymce = editor.editor,
                doc = tinymce.getDoc(),
                block = doc.createElement('div'),
                link = doc.createElement('a'),
                details = doc.createElement('span'),
                sig = tinymce.dom.get('_rc_sig') || tinymce.dom.get('v1_rc_sig');

            block.setAttribute('style', 'margin: 1em 0; padding: 0.8em 1em; border: 1px solid #dadce0; border-radius: 8px; display: inline-block; font-family: sans-serif;');
            link.setAttribute('href', file.url);
            link.setAttribute('style', 'color: #1a73e8; font-weight: bold; text-decoration: none;');
            link.textContent = file.name;
            details.setAttribute('style', 'color: #5f6368; font-size: 0.9em;');
            details.textContent = ' (' + file.size_text + ', Google Drive)' + (expires ? ' - ' + expires : '');
            block.appendChild(link);
            block.appendChild(details);

            if (sig && sig.parentNode) {
                sig.parentNode.insertBefore(block, sig);
            } else {
                tinymce.getBody().appendChild(block);
            }

            tinymce.undoManager.add();
        } else {
            var text = '\n' + file.name + ' (' + file.size_text + ', Google Drive)\n' + file.url + '\n'
                    + (expires ? expires + '\n' : ''),
                message = editor.get_content(),
                signature = rcmail.env.identity && rcmail.env.signatures && rcmail.env.signatures[rcmail.env.identity],
                pos = -1;

            // Before the signature and its "-- " separator line
            if (signature && signature.text) {
                $.each(['-- \n', ''], function (i, separator) {
                    var sig_text = separator + signature.text.replace(/\r\n/g, '\n');
                    pos = rcmail.env.top_posting ? message.indexOf(sig_text) : message.lastIndexOf(sig_text);
                    return pos < 0;
                });
            }

            editor.set_content(pos >= 0 ? message.substring(0, pos) + text + '\n' + message.substring(pos) : message + text);
        }

        rcmail.display_message(label('link_inserted', { name: file.name }), 'confirmation');
    });
});
