var canpost = mw.config.exists('canpost');
var template = '<div class="comment-thread"><div class="comment-post">'
  + '<div class="comment-avatar">'
  + '<img src=""></img>'
  + '</div>'
  + '<div class="comment-body">'
  + '<div class="comment-user"></div>'
  + '<div class="comment-text"></div>'
  + '<div class="comment-footer">'
  + '<span class="comment-time"></span>';
if (canpost) {
  template += '<span class="comment-reply">' + mw.msg('flowthread-ui-reply') + '</span>';
}

// User not signed in do not have right to vote
if (mw.user.getId() !== 0) {
  template += '<span class="comment-like">' + mw.msg('flowthread-ui-like') + ' <span></span></span>'
    + '<span class="comment-report">' + mw.msg('flowthread-ui-report') + ' <span></span></span>'
}

template += '<span class="comment-delete">' + mw.msg('flowthread-ui-delete') + '</span>';
template += '</div>'
  + '</div></div></div>';
var extAvatar = mw.config.get('wgUseAvatar');

var replyBoxTemplate = '<div class="comment-replybox">'
  + '<div class="comment-avatar">'
  + '<img src="' + getAvatar(mw.user.getId(), mw.user.id()) + '"></img>'
  + '</div>'
  + '<div class="comment-body">'
  + '<textarea placeholder="' + mw.msg('flowthread-ui-placeholder') + '"></textarea>'
  + '<div class="comment-toolbar">'
  + '<input type="checkbox" name="wikitext" value="" />'
  + mw.msg('flowthread-ui-usewikitext')
  + '<button class="comment-submit">' + mw.msg('flowthread-ui-submit') + '</button>'
  + '</div>'
  + '</div></div>';

function getAvatar(id, username) {
  if (id === 0 || !extAvatar) {
    return mw.config.get('wgDefaultAvatar');
  } else {
    return mw.util.getUrl('Special:Avatar/' + username);
  }
}

function getTimeString(time) {
  var m = moment(time).locale(mw.config.get('wgUserLanguage'));
  var diff = Date.now() - time;
  if (0 < diff && diff < 24 * 3600 * 1000) {
    return m.fromNow();
  } else {
    return m.format('LL, HH:mm:ss');
  }
}

function wrapText(text) {
  var span = $('<span/>');
  span.text(text);
  return span.wrapAll('<div/>').parent().html();
}

function wrapPageLink(page, name) {
  var link = $('<a/>');
  link.attr('href', mw.util.getUrl(page));
  link.text(name);
  return link.wrapAll('<div/>').parent().html();
}

var replyBox = null;

function Thread(post) {
  var self = this;
  var object = $(template);

  this.post = post;
  this.object = object;
  // $.data(object, 'flowthread', this);

  object.attr('comment-id', post.id);

  var userlink;
  if (post.userid !== 0) {
    userlink = wrapPageLink('User: ' + post.username, post.username);
  } else {
    userlink = wrapText(post.username);
  }
  object.find('.comment-user').html(userlink);
  object.find('.comment-avatar img').attr('src', getAvatar(post.userid, post.username));
  object.find('.comment-text').html(post.text);
  object.find('.comment-time').text(getTimeString(post.timestamp * 1000));

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

  // commentadmin-restricted and poster himself can delete comment
  if (mw.config.exists('commentadmin') || (post.userid && post.userid === mw.user.getId())) {
    object.find('.comment-delete').attr('enabled', '');
  }

  if (mw.user.getId() === 0) {
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
  if (replyBox) {
    replyBox.remove();
  }
  replyBox = createReplyBox(this.post.id);
  setFollowUp(this.post.id, replyBox);
}

Thread.sendComment = function(postid, text, wikitext) {
  var api = new mw.Api();
  var req = {
    action: 'flowthread',
    type: 'post',
    pageid: mw.config.get('wgArticleId'),
    postid: postid,
    content: text,
    wikitext: wikitext
  };
  api.get(req).done(reloadComments).fail(function(error) {
    alert(error);
  });
}

function reloadComments(offset) {
  offset = offset || 0;
  var api = new mw.Api();
  api.get({
    action: 'flowthread',
    type: 'list',
    pageid: mw.config.get('wgArticleId'),
    offset: offset
  }).done(function(data) {
    $('.comment-container').html('');
    data.flowthread.posts.forEach(function(item) {
      if (item.parentid === '') {
        $('.comment-container').append(new Thread(item).object);
      } else {
        setFollowUp(item.parentid, new Thread(item).object);
      }
    });
    pager.current = Math.floor(offset / 10);
    pager.count = Math.ceil(data.flowthread.count / 10);
    pager.repaint();
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
  var useWikitext = replyBox.find('[name=wikitext]');
  textarea.keyup(function(e) {
    if (e.ctrlKey && e.which === 13) submit.click();
    $(this).height(1);
    $(this).height(this.scrollHeight);
  });
  submit.click(function() {
    var text = textarea.val().trim();
    if (!text) {
      alert(mw.msg('flowthread-ui-nocontent'));
      return;
    }
    textarea.val('');
    Thread.sendComment(parentid, text, useWikitext[0].checked);
  });
  return replyBox;
}

/* Paginator support */
function Paginator() {
  this.object = $('<div class="comment-paginator"></div>');
  this.current = 0;
  this.count = 1;
}

Paginator.prototype.add = function(page) {
  var item = $('<span>' + (page + 1) + '</span>');
  if (page === this.current) {
    item.attr('current', '');
  }
  item.click(function() {
    reloadComments(page * 10);
  });
  this.object.append(item);
}

Paginator.prototype.addEllipse = function() {
  this.object.append('<span>...</span>')
}

Paginator.prototype.repaint = function() {
  this.object.html('');
  if (this.count === 1) {
    this.object.hide();
  } else {
    this.object.show();
  }
  var pageStart = Math.max(this.current - 2, 0);
  var pageEnd = Math.min(this.current + 4, this.count - 1);
  if (pageStart !== 0) {
    this.add(0);
  }
  if (pageStart > 1) {
    this.addEllipse();
  }
  for (var i = pageStart; i <= pageEnd; i++) {
    this.add(i);
  }
  if (this.count - pageEnd > 2) {
    this.addEllipse();
  }
  if (this.count - pageEnd !== 1) {
    this.add(this.count - 1);
  }
}

var pager = new Paginator();

$('#bodyContent').after('<div class="comment-container"></div>', pager.object, canpost ? createReplyBox('') : null);
reloadComments();