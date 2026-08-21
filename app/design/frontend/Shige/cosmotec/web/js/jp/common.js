define(['jquery'], function ($) {
    'use strict';


$(function () {

    // スマホ用 高さ調整
    if (window.matchMedia('(max-width: 767px)').matches) {
        var headerSearchContH = $(window).height();
        $('.ec-headerSearchCont').height(headerSearchContH - 63);
        var headerLinkBodyH = $(window).height();
        $('.ec-headerLink__body').height(headerLinkBodyH - 60 - 35);
        $(window).resize(function () {
            var headerSearchContH = $(window).height();
            $('.cover').height(headerSearchContH - 63);
            var headerLinkBodyH = $(window).height();
            $('.ec-headerLink__body').height(headerLinkBodyH - 60 - 35);
            var headerSearchH = $(window).height();
            $('html.is-active__ec-headerSearch').height(headerSearchH + 73);
            $('html.is-active__ec-headerSearch body').height(headerSearchH + 73);
        });
    }

    // PC・SP切り替え表示
    var switch_pc_width = 1440;
    var switch_device = (screen.width < 767 ? 'sp' : 'pc');
    var switch_view_mode = (switch_device == 'pc' || document.cookie.indexOf('switch_view_mode=pc') != -1 ? 'pc' : 'sp');

    if (switch_device == 'sp' && switch_view_mode == 'pc') {
        document.getElementsByName('viewport')[0].setAttribute('content', 'width=' + switch_pc_width + 'initial-scale=1');
    }

    if (switch_device == 'sp') {
        if (switch_view_mode == 'pc') {
            $('.ec-switch-sp').on('click', function () {
                var date = new Date();
                date.setTime(0);
                document.cookie = 'switch_view_mode=;expires='+date.toGMTString();
                location.reload(false);
            }).show();
        } else {
            $('.ec-switch-pc').on('click', function () {
                document.cookie = 'switch_view_mode=pc';
                location.reload(false);
            }).show();
        }
    }

    // ヘッダー 検索候補内 製品コードサジェストにマウスオーバー時に.is-active付与
    $('.is-code .ec-headerSearchCont__suggestItem').on({
        'mouseenter': function () {
            $('.is-code .ec-headerSearchCont__suggestItem').removeClass('is-active');
            $('#ec-headerSearch').removeClass('is-active');
            $(this).addClass('is-active');
            $('#ec-headerSearch').addClass('is-active');
        },
        'mouseleave': function () {
            $(this).hover(
                function () {
                    $('.is-code .ec-headerSearchCont__suggestItem').removeClass('is-active');
                }
            );
            $('#ec-headerSearchCont__side').on({
                'mouseenter': function () {
                    $(this).addClass('is-active');
                    $('#ec-headerSearch').addClass('is-active');
                },
                'mouseleave': function () {
                    $(this).removeClass('is-active');
                    $('#ec-headerSearch').removeClass('is-active');
                }
            });
            $('#ec-headerSearch').removeClass('is-active');
        }
    });

    // ヘッダー 製品カテゴリナビ .ec-itemNav__navItem click/hover時にhtmlに.is-active__ec-itemNavを付与
    $('.ec-itemNav__navList').each(function () {
        if (window.matchMedia('(max-width: 767px)').matches) {
            $(this).find('.ec-itemNav__subnavFirst').addClass('is-active');
            $('.ec-itemNav__navItemTitle').on('click', function () {
                event.preventDefault();
                $('html').removeClass('is-active__ec-itemNav');
                $('html').addClass('is-active__ec-itemNav');
                $('.ec-itemNav__navItemInner').removeClass('is-active');
                $(this).next('.ec-itemNav__navItemInner').addClass('is-active');
                var itemNavItemH = $(window).height();
                $('.ec-itemNav__navItemInner.is-active').height(itemNavItemH - 63);
                $(window).resize(function () {
                    var itemNavItemH = $(window).height();
                    $('.ec-itemNav__navItemInner.is-active').height(itemNavItemH - 63);
                });
            });
        } else {
            $('.ec-itemNav__navItemTitle').on({
                'click': function () {
                    event.preventDefault();
                    $('.ec-itemNav__subnav').children().removeClass('is-active');
                    $('.ec-itemNav__subnavItem').removeClass('is-active');
                    $('html').removeClass('is-active__ec-itemNav');
                    $('html').addClass('is-active__ec-itemNav');
                    $('.ec-itemNav__navItem').removeClass('is-active');
                    $(this).parent().addClass('is-active');
                }
            });
            $('.ec-itemNav__navList').on({
                'mouseleave': function () {
                    $('html').removeClass('is-active__ec-itemNav');
                    $('.ec-itemNav__navItem').removeClass('is-active');
                }
            });
        }
    });

    // ヘッダー 製品カテゴリナビ 階層表示
    $('.ec-itemNav__navList').each(function () {
        if (window.matchMedia('(max-width: 767px)').matches) {
            $('.ec-itemNav__navList').on('click', '.ec-itemNav__subnavFirst li.is-tree', function () {
                $('.ec-itemNav__subnavSecond').removeClass('is-active');
                $(this).parent().parent().next('.ec-itemNav__subnavSecond').addClass('is-active');
            });
            $('.ec-itemNav__navList').on('click', '.ec-itemNav__subnavSecond li.is-tree', function () {
                $('.ec-itemNav__subnavThird').removeClass('is-active');
                $(this).parent().parent().next('.ec-itemNav__subnavThird').addClass('is-active');
            });
            $('.ec-itemNav__navList').on('click', '.ec-itemNav__subnavHeadingPrev', function () {
                $(this).parent().parent().removeClass('is-active');
            });
            $('.ec-itemNav__navList').on('click', '.ec-itemNav__subnavFirst .ec-itemNav__subnavHeadingPrev', function () {
                $('html').removeClass('is-active__ec-itemNav');
                $('.ec-itemNav__navItemInner').removeClass('is-active');
                $('.ec-itemNav__navItemInner').removeAttr('style');
            });
        } else {
            $('.ec-itemNav__navList').on({
                'click': function () {
                    $('.ec-itemNav__subnavFirst .ec-itemNav__subnavItem').removeClass('is-active');
                    $('.ec-itemNav__subnavSecond').removeClass('is-active');
                    $('.ec-itemNav__subnavThird').removeClass('is-active');
                    if($(this).hasClass('is-tree')){
                        $(this).addClass('is-active');
                        $(this).parent().parent().next('.ec-itemNav__subnavSecond').addClass('is-active');
                        return false;
                    }
                }
            }, '.ec-itemNav__subnavFirst li');
            $('.ec-itemNav__navList').on({
                'click': function () {
                    $('.ec-itemNav__subnavSecond .ec-itemNav__subnavItem').removeClass('is-active');
                    $('.ec-itemNav__subnavThird').removeClass('is-active');
                    if($(this).hasClass('is-tree')){
                        if($(this).parent().parent().hasClass('is-active')){
                            $(this).addClass('is-active');
                            $(this).parent().parent().next('.ec-itemNav__subnavThird').addClass('is-active');
                        }
                        return false;
                    }
                }
            }, '.ec-itemNav__subnavSecond li');
        }
    }, '.ec-itemNav__subnav');

    $('.ec-itemNav__navList').on({
        'mouseleave' : function () {
            $('.ec-itemNav__subnavSecond').removeClass('is-active');
            $('.ec-itemNav__subnavThird').removeClass('is-active');
            $('.ec-itemNav__subnavFirst .ec-itemNav__subnavItem').removeClass('is-active');
            $('.ec-itemNav__subnavSecond .ec-itemNav__subnavItem').removeClass('is-active');
        }
    }, '.ec-itemNav__subnav');

    // ヘッダー SP版ナビゲーション
    $('.ec-headerLink__itemToggle').on("click", function () {
        $(this).next().slideToggle();
        $(this).toggleClass('is-active');
    });

    // フッター SP版ナビゲーション
    $('.ec-footerMenu__itemToggle').on("click", function () {
        $(this).next().slideToggle();
        $(this).toggleClass('is-active');
    });

    // フッター ページ上部へ戻る
    if (window.matchMedia('(min-width: 768px)').matches) {
        var isDisplay = false;
        var TopBtn = $('#ec-blockTopBtn');
        var TopBtnWrap = $('#ec-blockTopBtnWrap');
        var BottomPos = 40;
        $(window).scroll(function (e) {
            var $window = $(e.currentTarget);
            var WindowHeight = $window.height();
            var PageHeight = $(document).height();
            var footerHeight = $('#ec-layoutRole__footerWrap').height();
            var ScrollTop = $window.scrollTop();
            var MoveTopBtn = WindowHeight + ScrollTop + footerHeight - PageHeight;

            if ($(this).scrollTop() > 170) {
                if (isDisplay == false) {
                    isDisplay = true;
                    TopBtnWrap.stop().animate({
                        'bottom': '-20px'
                    }, 300);
                }
            } else {
                if (isDisplay) {
                    isDisplay = false;
                    TopBtnWrap.stop().animate({
                        'bottom': '-150px'
                    }, 300);
                }
            }

            if (ScrollTop >= PageHeight - WindowHeight - footerHeight + BottomPos) {
                TopBtn.css({
                    'margin-bottom': MoveTopBtn
                });
            } else {
                TopBtn.css({
                    'margin-bottom': BottomPos
                });
            }
        });

        TopBtn.click(function () {
            $('body,html').animate({
                scrollTop: 0
            }, 300);
            return false;
        });
    }

    // 絞り込むSP表示
    $(document).on('click', '.js-narrowBtn' , function() {
        $(this).parent().find('.ec-refineRole').addClass('is-active');
        $('body').addClass('is-refineFixed');
    });

    $(document).on('click', '.js-refineClose' , function() {
        $(this).parent('.ec-refineRole').removeClass('is-active');
        $('body').removeClass('is-refineFixed');
    });

    $(document).on('click', '.ec-refine__title' ,function() {
        $(this).parent('.ec-refine__category').toggleClass('is-active');
    });

    $(document).on('click', '.js-refineAccordion' ,function() {
        $(this).parent('.ec-refine__accordionHeader').toggleClass('is-close');
        $(this).parent('.ec-refine__accordionHeader').next('.ec-refine__subtree').slideToggle(150);
    });

    // $('.ec-refine__category').on('click', '.ec-refine__title' ,function() {
    //     $(this).parent('.ec-refine__category').toggleClass('is-active');
    // });

    // ページ内リンク スクロール
    $('a[href^="#"]').click(function() {
        var href= $(this).attr("href");
        var target = $(href == "#" || href == "" ? 'html' : href);
        var position = target.offset().top;
        $('body,html').animate({scrollTop:position}, 500, 'swing');
        return false;
    });

    // 複数行3点リーダー
    $('#ec-headerSearchCont__side .ec-productDetail__text').each(function() {
        var $target = $(this);
        var html = $target.html();
        var $clone = $target.clone();
        $clone
        .css({
            display: 'none',
            position : 'absolute',
            overflow : 'visible'
        })
        .width($target.width())
        .height('auto');
        $target.after($clone);
        while((html.length > 0) && ($clone.height() > $target.height())) {
            html = html.substr(0, html.length - 1);
            $clone.html(html + '...');
        }
        $target.html($clone.html());
        $clone.remove();
    });


    // ログインモーダル ログインボタンクリック
    $(document).on('click', '.js-loginModalSubmit', function (e) {
        var loginForm = $('form#login_mypage');
        if (loginForm.length === 0) {
            return;
        }
        e.preventDefault();

        // previous_urlを現在のURLで指定
        if (loginForm.find('input#previous_url').length === 0) {
            var previousUrl = $('<input type="hidden" id="previous_url" name="previous_url" value="' + location.href + '">');
            loginForm.append(previousUrl);
        } else {
            loginForm.find('input#previous_url').val(location.href);
        }

        loginForm.submit();
    });

    // ロケールを日本語に変更（PC用）
    $(document).on('click', '.js-change-japanese', function (e) {
        var sysEnv = $(this).closest('.ec-headerOutsetRole__subnav').data('sys-env');
        var currentUrl = window.location.pathname;

        if (currentUrl === "/shopping/complete") {
            switch (sysEnv) {
                case 'prod':
                    document.cookie = "locale=ja; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "www.cosmotec-co.jp") {
                        window.location.href = "https://www.cosmotec-co.jp/";
                    } else {
                        window.location.href = "https://" + window.location.hostname + "/";
                    }
                    break;
                case 'stage':
                    document.cookie = "stg_locale=ja; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "stage.cosmotec-co.jp") {
                        window.location.href = "https://stage.cosmotec-co.jp/";
                    } else {
                        window.location.href = "https://" + window.location.hostname + "/";
                    }
                    break;
                default:
                    document.cookie = "locale=ja; path=/; secure; samesite=medium;";
                    window.location.reload();
            }
        } else {
            switch (sysEnv) {
                case 'prod':
                    document.cookie = "locale=ja; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "www.cosmotec-co.jp") {
                        window.location.href = "https://www.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else {
                        window.location.reload();
                    }
                    break;
                case 'stage':
                    document.cookie = "stg_locale=ja; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "stage.cosmotec-co.jp") {
                        window.location.href = "https://stage.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else {
                        window.location.reload();
                    }
                    break;
                default:
                    document.cookie = "locale=ja; path=/; secure; samesite=medium;";
                    window.location.reload();
            }
        }
    });

    // ロケールを英語に変更（PC用）
    $(document).on('click', '.js-change-english', function (e) {
        var sysEnv = $(this).closest('.ec-headerOutsetRole__subnav').data('sys-env');
        var currentUrl = window.location.pathname;

        if (currentUrl === "/shopping/complete") {
            switch (sysEnv) {
                case 'prod':
                    document.cookie = "locale=en; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "en.cosmotec-co.jp") {
                        window.location.href = "https://en.cosmotec-co.jp/";
                    } else {
                        window.location.href = "https://" + window.location.hostname + "/";
                    }
                    break;
                case 'stage':
                    document.cookie = "stg_locale=en; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "stage-en.cosmotec-co.jp") {
                        window.location.href = "https://stage-en.cosmotec-co.jp/";
                    } else {
                        window.location.href = "https://" + window.location.hostname + "/";
                    }
                    break;
                default:
                    document.cookie = "locale=en; path=/; secure; samesite=medium;";
                    window.location.reload();
            }
        } else {
            switch (sysEnv) {
                case 'prod':
                    document.cookie = "locale=en; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "en.cosmotec-co.jp") {
                        window.location.href = "https://en.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else {
                        window.location.reload();
                    }
                    break;
                case 'stage':
                    document.cookie = "stg_locale=en; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    if (window.location.hostname !== "stage-en.cosmotec-co.jp") {
                        window.location.href = "https://stage-en.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else {
                        window.location.reload();
                    }
                    break;
                default:
                    document.cookie = "locale=en; path=/; secure; samesite=medium;";
                    window.location.reload();
            }
        }
    });

    // ロケールを変更（SP用）
    $(document).on('change', '.js-change-locale', function (e) {
        var locale = $(this).val();
        var sysEnv = $(this).data('sys-env');

        var currentUrl = window.location.pathname;

        if (currentUrl === "/shopping/complete") {
            switch (sysEnv) {
                case 'prod':
                    document.cookie = "locale="+locale+"; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    // 言語によってドメインを切り替える処理を追加
                    if (locale === "ja" && window.location.hostname !== "www.cosmotec-co.jp") {
                        window.location.href = "https://www.cosmotec-co.jp/";
                    } else if (locale === "en" && window.location.hostname !== "en.cosmotec-co.jp") {
                        window.location.href = "https://en.cosmotec-co.jp/";
                    } else {
                        window.location.reload();
                    }
                    break;
                case 'stage':
                    document.cookie = "stg_locale="+locale+"; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    // 言語によってドメインを切り替える処理を追加
                    if (locale === "ja" && window.location.hostname !== "stage.cosmotec-co.jp") {
                        window.location.href = "https://stage.cosmotec-co.jp/";
                    } else if (locale === "en" && window.location.hostname !== "stage-en.cosmotec-co.jp") {
                        window.location.href = "https://stage-en.cosmotec-co.jp/";
                    } else {
                        window.location.reload();
                    }
                    break;
                default:
                    document.cookie = "locale="+locale+"; path=/; secure; samesite=medium;";
                    window.location.reload();
            }
        } else {
            switch (sysEnv) {
                case 'prod':
                    document.cookie = "locale="+locale+"; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    // 言語によってドメインを切り替える処理を追加
                    if (locale === "ja" && window.location.hostname !== "www.cosmotec-co.jp") {
                        window.location.href = "https://www.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else if (locale === "en" && window.location.hostname !== "en.cosmotec-co.jp") {
                        window.location.href = "https://en.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else {
                        window.location.reload();
                    }
                    break;
                case 'stage':
                    document.cookie = "stg_locale="+locale+"; path=/; secure; domain=.cosmotec-co.jp; samesite=medium;";
                    // 言語によってドメインを切り替える処理を追加
                    if (locale === "ja" && window.location.hostname !== "stage.cosmotec-co.jp") {
                        window.location.href = "https://stage.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else if (locale === "en" && window.location.hostname !== "stage-en.cosmotec-co.jp") {
                        window.location.href = "https://stage-en.cosmotec-co.jp" + window.location.pathname + window.location.search + window.location.hash;
                    } else {
                        window.location.reload();
                    }
                    break;
                default:
                    document.cookie = "locale="+locale+"; path=/; secure; samesite=medium;";
                    window.location.reload();
            }
        }
    });

    // 初期ロケールを設定（SP用）
    if ($('.js-change-locale').data('sys-env') === 'stage') {
        ///$(".js-change-locale").val($.cookie("stg_locale"));
    } else {
        ///$(".js-change-locale").val($.cookie("locale"));
    }
});

// ローディング表示
var showLoader = function() {
    $('.ec-loader-overlay').addClass('active');
};

// ローディング非表示
var hideLoader = function() {
    $('.ec-loader-overlay').removeClass('active');
};

// ローディング（ボックス型）表示
var showBoxLoader = function(parentElem) {
    $parentElem = $(parentElem);

    $parentElem.addClass('ec-loaderBox');

    $('.ec-loader-overlay').clone().appendTo($parentElem);
    $parentElem.find('.ec-loader-overlay').addClass('active');
};

// ローディング（ボックス型）非表示
var hideBoxLoader = function(parentElem) {
    $parentElem = $(parentElem);

    $parentElem.removeClass('ec-loaderBox');

    $parentElem.find('.ec-loader-overlay').remove();
};


$('#item_list').on('click','.js-categoryList',function() {
    $(this).parents('.ec-headingResult').next('.ec-subCategory').removeClass('is-categoryTile');
    $(this).parents('.ec-headingResult').next('.ec-subCategory').addClass('is-categoryList');
    $(this).next().removeClass('is-current');
    $(this).addClass('is-current');
});

// 繧ｿ繧､繝ｫ陦ｨ遉ｺ縺ｫ蛻�ｊ譖ｿ縺医ｋ�医し繝悶き繝�ざ繝ｪ��
$('#item_list').on('click','.js-categoryTile',function() {
    $(this).parents('.ec-headingResult').next('.ec-subCategory').removeClass('is-categoryList');
    $(this).parents('.ec-headingResult').next('.ec-subCategory').addClass('is-categoryTile');
    $(this).prev().removeClass('is-current');
    $(this).addClass('is-current');
});

});
