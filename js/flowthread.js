var template = '<div class="comment-thread"><div class="comment-post">\
		<div class="comment-avatar">\
			<img src="/w/extensions/FlowThread/css/avatar.jpg"></img>\
		</div>\
		<div class="comment-body">\
			<div class="comment-user"></div>\
			<div class="comment-text"></div>\
			<div class="comment-footer">\
				<span class="comment-time"></span>\
				<span class="comment-reply">回复</span>\
				<span class="comment-like" enabled>赞 <span></span></span>\
				<span class="comment-report" enabled>举报</span>\
                <span class="comment-delete">删除</span>\
			</div>\
		</div></div></div>';
var replyBoxTemplate = '<div class="comment-replybox">\
	<div class="comment-avatar">\
		<img src="/w/extensions/FlowThread/css/avatar.jpg"></img>\
	</div>\
	<div class="comment-body">\
		<textarea placeholder="说点什么吧…"></textarea>\
		<div class="comment-toolbar">\
            <input type="checkbox" name="wikitext" value="" />\
            使用维基文本\
			<button class="comment-submit">发布</button>\
		</div>\
	</div></div>';

var replyBox = $(replyBox);

function Thread(post) {
    var self = this;
    var object = $(template);

    this.post = post;
    this.object = object;
    // $.data(object, 'flowthread', this);

    object.attr('comment-id', post.id);
    if (post.userid !== 0) {
        var userlink = $('<a></a>');
        userlink.attr('href', mw.config.get('wgArticlePath').replace('$1', 'User:' + post.username));
        userlink.text(post.username);
        object.find('.comment-user').append(userlink);
    } else {
        object.find('.comment-user').text(post.username);
    }
    object.find('.comment-text').html(post.text);
    object.find('.comment-time').text(new Date(post.timestamp * 1000).toLocaleString('zh-CN'));

    object.find('.comment-reply').click(function() {
        self.reply();
    });
    object.find('.comment-like').click(function() {
        if (object.find('.comment-like').attr('liked') !== undefined) {
            self.dislike(post.id);
        } else {
            self.like(post.id);
        }
    });
    object.find('.comment-report').click(function() {
        if (object.find('.comment-report').attr('reported') !== undefined) {
            self.dislike(post.id);
        } else {
            self.report(post.id);
        }
    });
    object.find('.comment-delete').click(function() {
        self.delete(post.id);
    });

    if(mw.config.exists('commentadmin')) {
        object.find('.comment-delete').attr('enabled', '');
    }

    if(mw.user.getId() === 0) {
        object.find('.comment-like, .comment-report').removeAttr('enabled');
    }

    if (post.myatt === 1) {
        object.find('.comment-like').attr('liked', '');
    } else if (post.myatt === 2) {
        object.find('.comment-report').attr('reported', '');
    }
    if (post.like !== 0) {
        object.find('.comment-like span').text('(' + post.like + ')');
    }
}

Thread.prototype.like = function() {
    var api = new mw.Api();
    api.get({
        action: 'flowthread',
        type: 'like',
        postid: this.post.id
    });
    this.object.find('.comment-like').first().attr('liked', '');
    this.object.find('.comment-report').first().removeAttr('reported');
}

Thread.prototype.dislike = function() {
    var api = new mw.Api();
    api.get({
        action: 'flowthread',
        type: 'dislike',
        postid: this.post.id
    });
    this.object.find('.comment-like').first().removeAttr('liked');
    this.object.find('.comment-report').first().removeAttr('reported');
}

Thread.prototype.report = function() {
    var api = new mw.Api();
    api.get({
        action: 'flowthread',
        type: 'report',
        postid: this.post.id
    });
    this.object.find('.comment-like').first().removeAttr('liked');
    this.object.find('.comment-report').first().attr('reported', '');
}

Thread.prototype.delete = function() {
    var api = new mw.Api();
    api.get({
        action: 'flowthread',
        type: 'delete',
        postid: this.post.id
    });
    this.object.remove();
}

Thread.prototype.reply = function() {
    setFollowUp(this.post.id, createReplyBox(this.post.id));
}


Thread.sendComment = function(postid, text) {
    var api = new mw.Api();
    api.get({
        action: 'flowthread',
        type: 'post',
        pageid: mw.config.get('wgArticleId'),
        postid: postid,
        content: text
    }).done(reloadComments);
}

function reloadComments(comments) {
    var api = new mw.Api();
    api.get({
        action: 'flowthread',
        type: 'list',
        pageid: mw.config.get('wgArticleId')
    }).done(function(data) {
        $('.comment-container').html('');
        data.flowthread.forEach(function(item) {
            if (item.parentid === 0) {
                $('.comment-container').prepend(new Thread(item).object);
            } else {
                setFollowUp(item.parentid, new Thread(item).object);
            }
        });
    });
}

function setFollowUp(postid, follow) {
    var obj = $('[comment-id=' + postid + '] > .comment-post');
    obj.after(follow);
}

function createReplyBox(parentid) {
    var replyBox = $(replyBoxTemplate);
    var textarea = replyBox.find('textarea');
    var submit = replyBox.find('.comment-submit');
    textarea.keyup(function(e) {
        if (e.ctrlKey && e.which === 13) submit.click();
        $(this).height(1);
        $(this).height(this.scrollHeight);
    });
    submit.click(function() {
        if (!textarea.val()) {
            alert('你还没写内容呢');
            return;
        }
        var text = textarea.val();
        textarea.val('');
        Thread.sendComment(parentid, text);
    });
    return replyBox;
}

$(function() {
    $('#bodyContent').after('<div class="comment-container"></div>', createReplyBox(0));
    reloadComments();
    // setInterval(function() {
    //     reloadComments();
    // }, 5000);
});