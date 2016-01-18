var canpost = mw.config.exists('canpost');
var config = mw.config.get('wgFlowThreadConfig');

/* Get avatar by user name */
function getAvatar(id, username) {
    if(id===0) {
        return config.AnonymousAvatar;
    }else{
        return config.Avatar.replace(/\$\{username\}/g, username);
    }
}

/* Get user friendly time string (such as 1 hour age) */
function getTimeString(time) {
  var m = moment(time).locale(mw.config.get('wgUserLanguage'));
  var diff = Date.now() - time;
  if (0 < diff && diff < 24 * 3600 * 1000) {
    return m.fromNow();
  } else {
    return m.format('LL, HH:mm:ss');
  }
}

function Thread() {
  var template = '<div class="comment-thread"><div class="comment-post">'
    + '<div class="comment-avatar">'
    + '<img src=""></img>'
    + '</div>'
    + '<div class="comment-body">'
    + '<div class="comment-user"></div>'
    + '<div class="comment-text"></div>'
    + '<div class="comment-footer">'
    + '<span class="comment-time"></span>'
    + '</div>'
    + '</div></div></div>';

  var object = $(template);

  this.post = null;
  this.object = object;
}

Thread.prototype.init = function(post) {
  var object = this.object;
  this.post = post;
  object.attr('comment-id', post.id);

  var userlink;
  if (post.userid !== 0) {
    userlink = wrapPageLink('User:' + post.username, post.username);
  } else {
    userlink = wrapText(post.username);
  }
  object.find('.comment-user').html(userlink);
  object.find('.comment-avatar img').attr('src', getAvatar(post.userid, post.username));
  object.find('.comment-text').html(post.text);
  object.find('.comment-time')
    .text(getTimeString(post.timestamp * 1000))
    .siblings().remove(); // Remove all button after init
}

Thread.prototype.addButton = function(type, text, listener) {
  return $('<span>')
    .addClass('comment-' + type)
    .text(text)
    .click(listener)
    .appendTo(this.object.find('.comment-footer'));
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

function ReplyBox() {
  var template = '<div class="comment-replybox">'
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

  var self = this;
  var object = $(template);
  this.object = object;

  object.find('textarea').keyup(function(e) {
    if (e.ctrlKey && e.which === 13) submit.click();
    self.pack();
  });
}

ReplyBox.prototype.pack = function() {
  var textarea = this.object.find('textarea');
  textarea.height(1).height(textarea[0].scrollHeight);
}