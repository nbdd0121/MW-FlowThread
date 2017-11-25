var canpost = mw.config.exists('canpost');
var ownpage = mw.config.exists('commentadmin') || mw.config.get('wgNamespaceNumber') === 2 && mw.config.get('wgTitle').replace('/$', '') === mw.user.id();

var replyBox = null;

/* Get user preference */
/* Returns positive result for non-user namespaces */
function getUserCommentPreference() {
  var optFlag = document.getElementById("flowthread-user-optout");

  // No one will even change the default user namespace ID in MediaWiki
  // Changing this value considered unsupported
  if (optFlag && mw.config.get("wgNamespaceNumber") === 2) {
      return false;
  }
  return true;
}

function createThread(post) {
  var thread = new Thread();
  var object = thread.object;
  thread.init(post);

  if (canpost) {
    thread.addButton('reply', mw.msg('flowthread-ui-reply'), function() {
      thread.reply();
    });
  }

  // User not signed in do not have right to vote
  if (mw.user.getId() !== 0) {
    var likeNum = post.like ? '(' + post.like + ')' : '';
    thread.addButton('like', mw.msg('flowthread-ui-like') + likeNum, function() {
      if (object.find('.comment-like').first().attr('liked') !== undefined) {
        thread.dislike();
      } else {
        thread.like();
      }
    });
    thread.addButton('report', mw.msg('flowthread-ui-report'), function() {
      if (object.find('.comment-report').first().attr('reported') !== undefined) {
        thread.dislike();
      } else {
        thread.report();
      }
    });
  }

  // commentadmin-restricted and poster himself can delete comment
  if (ownpage || (post.userid && post.userid === mw.user.getId())) {
    thread.addButton('delete', mw.msg('flowthread-ui-delete'), function() {
      thread.delete();
      if ($('.comment-container-top').children('.comment-thread').length === 0) {
        $('.comment-container-top').attr('disabled', '');
      }
    });
  }

  if (post.myatt === 1) {
    object.find('.comment-like').attr('liked', '');
  } else if (post.myatt === 2) {
    object.find('.comment-report').attr('reported', '');
  }

  return thread;
}

Thread.prototype.reply = function() {
  if (replyBox) {
    replyBox.remove();
  }
  replyBox = createReplyBox(this.post.id);
  this.appendChild({
    object: replyBox
  });
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
  api.get(req).done(reloadComments).fail(function(error, obj) {
    if (obj.error)
      showMsgDialog(obj.error.info);
    else if (error === 'http')
      showMsgDialog(mw.msg('flowthread-ui-networkerror'));
    else
      showMsgDialog(error);
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
    $('.comment-container-top').html('<div>' + mw.msg('flowthread-ui-popular') + '</div>').attr('disabled', '');
    $('.comment-container').html('');
    var canpostbak = canpost;
    canpost = false; // No reply for topped comments
    data.flowthread.popular.forEach(function(item) {
      var obj = createThread(item);
      obj.markAsPopular();
      $('.comment-container-top').removeAttr('disabled').append(obj.object);
    });
    canpost = canpostbak;
    data.flowthread.posts.forEach(function(item) {
      var obj = createThread(item);
      if (item.parentid === '') {
        $('.comment-container').append(obj.object);
      } else {
        Thread.fromId(item.parentid).appendChild(obj);
      }
    });
    pager.current = Math.floor(offset / 10);
    pager.count = Math.ceil(data.flowthread.count / 10);
    pager.repaint();

    if (location.hash.substring(0, 9) === '#comment-') {
      var hash = location.hash;
      location.replace('#');
      location.replace(hash);
    }
  });
}

function setFollowUp(postid, follow) {
  var obj = $('#comment-' + postid + ' > .comment-post');
  obj.after(follow);
}

function createReplyBox(parentid) {
  var replyBox = new ReplyBox();

  replyBox.onSubmit = function() {
    var text = replyBox.getValue().trim();
    if (!text) {
      showMsgDialog(mw.msg('flowthread-ui-nocontent'));
      return;
    }
    replyBox.setValue('');
    Thread.sendComment(parentid, text, replyBox.isInWikitextMode());
  };
  return replyBox.object;
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
  if (this.count <= 1) {
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

$('#bodyContent').after('<div class="comment-container-top" disabled></div>', '<div class="comment-container"></div>', pager.object, function () {
  var userPreference = getUserCommentPreference();
  if (canpost && userPreference) return createReplyBox('');
  
  var noticeContainer = $('<div>').addClass('comment-bannotice');
  
  if (!userPreference) {
    noticeContainer.html(config.UserOptOutNotice);
  } else {
    noticeContainer.html(config.CantPostNotice);
  }
  
  return noticeContainer;
}());

if (mw.util.getParamValue('flowthread-page')) {
  reloadComments((parseInt(mw.util.getParamValue('flowthread-page')) - 1) * 10);
} else {
  reloadComments();
}
