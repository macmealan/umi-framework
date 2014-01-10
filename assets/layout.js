$(function() {
	var stickyNav = $('.umi-side-nav'),
		stickyNavList = stickyNav.find('.menu'),
		header = $('.header'),
		headerHeight = header[0].offsetHeight + 1,
		content = $('.content.small-12'),
		footer = $('.footer'),
		sideNavAnchor = stickyNav.find('.side-nav-anchor');

	function correctHeight(){
		var footerTopPosition = footer.offset().top - $(window).scrollTop();
		if(footerTopPosition < $(window).height()){
			stickyNav[0].style.paddingBottom = $(window).height() - footerTopPosition + 'px';
		} else{
			stickyNav[0].style.paddingBottom = '0px';
		}
	}

	if(stickyNav.length){
		header.addClass('fixed');

		stickyNav.on('click', '.pull-nav', function(){
			if( $(this).hasClass('active') ){
				$(this).removeClass('active');
				stickyNav.animate({'marginLeft': - stickyNav[0].offsetWidth + 'px'}, 240);
				stickyNav.removeClass('of-canvas');
			}else{
				$(this).addClass('active');
				stickyNav.animate({'marginLeft': - stickyNav[0].offsetWidth - stickyNav.offset().left +'px'}, 240);
				stickyNav.addClass('of-canvas');
			}
		});

		sideNavAnchor.on('click', function(){
			if( stickyNav.hasClass('of-canvas') ){
				stickyNav.find('.pull-nav').removeClass('active');
				stickyNav.animate({'marginLeft': - stickyNav[0].offsetWidth + 'px'}, 240);
			}
		});

		window.onscroll = function(e) {
			correctHeight();
			if($(window).scrollTop() > headerHeight){
				stickyNav.addClass('is-scrolled');
			} else{
				stickyNav.removeClass('is-scrolled');
			}
		}

		$(window).resize(function(){
			if($('body').width() > 1100){
				stickyNav.removeAttr('style');
			}
		});

		stickyNav.on('click', '.umi-to-top-button', function(){
			window.location.hash = '';
			$('body').animate({scrollTop:0}, 50);
		});
	}

	$('.programlisting').each(function(i, e) {hljs.highlightBlock(e)});
	$(document).foundation();
});