define(['jquery'], function ($) {
    'use strict';



 
///$(function () {
    var category_refine_search = "Refine your search by this category";
    //var uriCategoryJson = "https://static.cosmotec-co.jp/html/template/default/data/json/category_en.json";
    //var uriItemJson = "https://static.cosmotec-co.jp/html/template/default/data/json/item_en.json";

    var uriCategoryJson = require.toUrl("js/jp/data/category_en.json");
    var uriItemJson = '';///require.toUrl("js/jp/data/item_en.json");

    var uriSearchTagJson = '';///"https://static.cosmotec-co.jp/html/template/default/data/json/search_tag.json";
    var uriProductJson =  ''; //"https://static.cosmotec-co.jp/html/template/default/data/json/product.json";

    var uriProductList = ''; // "https://en.cosmotec-co.jp/products/list";
    var uriProductDetailItemJsonData = ''; /// "https://en.cosmotec-co.jp/products/detail/itemJsonDataId";
    var uriProductDetailSearchTag= ''; ///"https://en.cosmotec-co.jp/products/detail/searchTagSubCategoryJsonData";
    var uriProductJsonDetailItem = ''; ///"https://en.cosmotec-co.jp/products/detail/productJsonDataItemId/product_id/productJsonDataProductId";
    var uriProductSearch = ''; ///"https://en.cosmotec-co.jp/products/search/";
    var uriTopSpecificationRefineDisplay = ''; ////"https://en.cosmotec-co.jp/top_specification_refine_display";
    var uriSpSpecificationDisplay = '';///"https://en.cosmotec-co.jp/sp_specification_display";
    var uriIsLogined = ''; /////"https://en.cosmotec-co.jp/asyn_login/";

    var history_type = "Model:";
    var history_product_code = "Product code :";
    var history_yen = "JPY";
    var history_yen_one_side_parentheses = "JPY)";
    var tax_included_one_side_parentheses = "(with tax ";
    var tax_excluded_parentheses = "(Without Tax)";
    var history_add_to_cart = "Add to cart";
    var separately_estimate = "Separate estimate";
    var common_quantity = "Quantity";
    var refine_search_message = "Failed to narrow-down search.";
    var cad_2d = "2D CAD";
    var cad_3d = "3D CAD";
    var cad_unavailable = "CAD<br>Not Available";
    var cad_request = "Request for CAD";

    var isWP = false;
    var isLogined = 1;

    var categoryurls = [];
    categoryurls['1'] = '/feedthrough.html';
    categoryurls['11'] = '/feedthrough/coaxial.html';
    categoryurls['12'] = '/feedthrough/coaxial/coaxial-bnc-type.html';

///}); 


///$(function () {
    var timerSeconds = 1000;
    var searchRunSeconds = 300;

    // 第3階層テンプレート
    const subnavSecondTemplate =
        '<div class="ec-itemNav__subnavSecond" id="__id__">' +
        '<div class="ec-itemNav__subnavHeading">' +
        '<span class="ec-itemNav__subnavHeadingPrev"><b aria-hidden="true" class="iconfont iconfont-angle-left"></b></span>' +
        '<span class="ec-itemNav__subnavHeadingText">__name__</span>' +
        '</div>' +
        '<ul class="ec-itemNav__subnavList"></ul>' +
        '</div>';
    // 第4階層テンプレート
    const subnavThirdTemplate =
        '<div class="ec-itemNav__subnavThird" id="__id__">' +
        '<div class="ec-itemNav__subnavHeading">' +
        '<span class="ec-itemNav__subnavHeadingPrev"><b aria-hidden="true" class="iconfont iconfont-angle-left"></b></span>' +
        '<span class="ec-itemNav__subnavHeadingText">__name__</span>' +
        '</div>' +
        '<ul class="ec-itemNav__subnavList"></ul>' +
        '</div>';
    // カテゴリテンプレート
    const itemTemplate =
        '<li class="ec-itemNav__subnavItem" id="__id__">' +
        '<a href="__URL__"><img src="__imgPath__"  alt="__name__" data-c="2">__name__</a>' +
        '</li>';
    // 絞り込み検索テンプレート
    const filterTemplate =
        '<li class="ec-itemNav__subnavFilter">' +
        '<a href="__URL__">' +
        '<b aria-hidden="true" class="iconfont iconfont-search"></b>' +
        category_refine_search +
        '</a>' +
        '</li>';

    //  指定したidを持つカテゴリの情報を取得
    var filterCategory = function (id) {
        var length = LaterSecondCategoryData.length;
        var filterData = LaterSecondCategoryData.filter(function (item, index) {
            for (var i = 0; i < length; i++) {
                if (item.id == id) return true;
            }
        });

        filterData[0]["children"].sort(function (a, b) {
            if (a.sort_no < b.sort_no) return -1;
            if (a.sort_no > b.sort_no) return 1;
            return 0;
        });

        return filterData[0]["children"];
    };

    // 第1階層のカテゴリ画像と第2階層のカテゴリをセット
    var LaterSecondCategoryData = [];
    var addSubnavFirstCategory = function (category) {
        var length = category["id"].length;
        var has_child = [];
        for(var i = 0; i < length; i++) {
            if(category["hierarchy"][i] === 1){
                if(category["children"][i] && category["children"][i].length > 0){
                    has_child.push(category["id"][i]);
                }
            }
            if(category["hierarchy"][i] === 2){
                // 第2階層
                // カテゴリを追加
                var addItem = itemTemplate;
                addItem = addItem.replace(/__id__/g, category["id"][i]);
                if (categoryurls[category["id"][i]]) {
                    addItem = addItem.replace(/__URL__/g, categoryurls[category["id"][i]]);
                } else {
                    addItem = addItem.replace(/__URL__/g, uriProductList + "?category_id=" + category["id"][i]);
                }
                addItem = addItem.replace(/__imgPath__/g, category["img_file_s"][i]);
                addItem = addItem.replace(/__name__/g, category["name"][i]);
                $('#'+category["parent_id"][i]).find('.ec-itemNav__subnavFirst').find('.ec-itemNav__subnavList').append(addItem);
                // 子カテゴリが存在する場合
                if(category["children"][i] && category["children"][i].length > 0) {
                    // .is-treeを付与
                    $('#' + category["parent_id"][i]).find('.ec-itemNav__subnavFirst').addClass('is-tree');
                    $('#' + category["id"][i]).addClass('is-tree');
                    $('#' + category["id"][i]).addClass('js-is-one-lower-category');
                    if ($('#' + category["parent_id"][i]).find('.ec-itemNav__subnavSecond').length <= 0) {
                        // 第3階層用のテンプレートを追加
                        var addSubnavSecond = subnavSecondTemplate;
                        addSubnavSecond = addSubnavSecond.replace(/__name__/g, category["name"][i]);
                        addSubnavSecond = addSubnavSecond.replace(/__id__/g, category["id"][i]);
                        $('#' + category["parent_id"][i]).find('.ec-itemNav__subnav').append(addSubnavSecond);
                    }
                    var itemArray = {
                        id: category["id"][i],
                        children: category["children"][i],
                    };
                    LaterSecondCategoryData.push(itemArray);
                }
            }
            else{
                // 第3階層以降
                if (category["hierarchy"][i] === 3) {
                    // 子カテゴリが存在する場合
                    if (category["children"][i] && category["children"][i].length > 0) {
                        if($('#'+category["parent_id"][i]).parent().parent().parent().find('.ec-itemNav__subnavThird').length <= 0){
                            // 第3階層用のテンプレートを追加
                            var addSubnavThird = subnavThirdTemplate;
                            addSubnavThird = addSubnavThird.replace(/__name__/g, category["name"][i]);
                            addSubnavThird = addSubnavThird.replace(/__id__/g, category["id"][i]);
                            $('#' + category["parent_id"][i]).parent().parent().parent().append(addSubnavThird);
                        }
                    }
                }
                var itemArray = {
                    id: category["id"][i],
                    children: category["children"][i],
                };
                LaterSecondCategoryData.push(itemArray);
            }
        }
        // 第2階層に絞込検索を追加
        for(var j=0; j<has_child.length; j++){
            var addFilter = filterTemplate;
            if (categoryurls[has_child[j]]) {
                addFilter = addFilter.replace(/__URL__/g, categoryurls[has_child[j]]);
            } else {
                addFilter = addFilter.replace(/__URL__/g, uriProductList+"?category_id=" + has_child[j]);
            }
            ////addFilter = addFilter.replace(/__URL__/g, uriProductList+"?category_id=" + has_child[j]);
            $('#'+has_child[j]).find('.ec-itemNav__subnavFirst').find('.ec-itemNav__subnavList').append(addFilter);
        }
    };

    // 第3階層のカテゴリをセット
    var addSubnavSecondCategory = function (parent_id) {
        // 第3階層を初期化
        $('.ec-itemNav__subnavSecond').find('.ec-itemNav__subnavList').children().remove();
        $('.ec-itemNav__subnavThird').find('.ec-itemNav__subnavList').children().remove();
        // 指定したidのカテゴリ情報を取得
        var addCategoryData = filterCategory(parent_id);
        var length = addCategoryData.length;
        for (var i = 0; i < addCategoryData.length; i++) {
            // カテゴリを追加
            var addItem = itemTemplate;
            addItem = addItem.replace(/__id__/g, addCategoryData[i]["id"]);
            if (categoryurls[addCategoryData[i]["id"]]) {
                addItem = addItem.replace(/__URL__/g, categoryurls[addCategoryData[i]["id"]]);
            } else {
                addItem = addItem.replace(/__URL__/g, uriProductList + "?category_id=" + addCategoryData[i]["id"]);
            }
            ////addItem = addItem.replace(/__URL__/g, uriProductList + "?category_id=" + addCategoryData[i]["id"]);
            addItem = addItem.replace(/__imgPath__/g, addCategoryData[i]["img_file_s"]);
            addItem = addItem.replace(/__name__/g, addCategoryData[i]["name"]);
            $('#'+parent_id).parent().parent().parent().find('.ec-itemNav__subnavSecond').find('.ec-itemNav__subnavList').append(addItem);
            if(addCategoryData[i]["is_child"] === true){
                // .id-treeを付与
                $('#' + addCategoryData[i]["id"]).addClass('is-tree');
                $('#' + addCategoryData[i]["id"]).addClass('js-is-one-lower-category');
            }
            if(i === addCategoryData.length - 1){
                var addFilter = filterTemplate;
                if (categoryurls[parent_id]) {
                    addFilter = addFilter.replace(/__URL__/g, categoryurls[parent_id]);
                } else {
                    addFilter = addFilter.replace(/__URL__/g, uriProductList+"?category_id=" + parent_id);
                }
                ///addFilter = addFilter.replace(/__URL__/g, uriProductList+"?category_id=" + parent_id);
                $('#'+parent_id).parent().parent().parent().find('.ec-itemNav__subnavSecond').find('.ec-itemNav__subnavList').append(addFilter);
            }
        }
    };

    // 第4階層のカテゴリをセット
    var addSubnavThirdCategory = function (parent_id) {
        // 第4階層を初期化
        $('.ec-itemNav__subnavThird').find('.ec-itemNav__subnavList').children().remove();
        // 指定したidのカテゴリ情報を取得
        var addCategoryData = filterCategory(parent_id);
        for (var i = 0; i < addCategoryData.length; i++) {
            // カテゴリを追加
            var addItem = itemTemplate;
            addItem = addItem.replace(/__id__/g, addCategoryData[i]["id"]);
            if (categoryurls[addCategoryData[i]["id"]]) {
                addItem = addItem.replace(/__URL__/g, categoryurls[addCategoryData[i]["id"]]);
            } else {
                addItem = addItem.replace(/__URL__/g, uriProductList + "?category_id=" + addCategoryData[i]["id"]);
            }
            ////addItem = addItem.replace(/__URL__/g, uriProductList + "?category_id=" + addCategoryData[i]["id"]);
            addItem = addItem.replace(/__imgPath__/g, addCategoryData[i]["img_file_s"]);
            addItem = addItem.replace(/__name__/g, addCategoryData[i]["name"]);
            $('#'+parent_id).parent().parent().parent().find('.ec-itemNav__subnavThird').find('.ec-itemNav__subnavList').append(addItem);
            if(i === addCategoryData.length - 1){
                var addFilter = filterTemplate;
                if (categoryurls[parent_id]) {
                    addFilter = addFilter.replace(/__URL__/g, categoryurls[parent_id]);
                } else {
                    addFilter = addFilter.replace(/__URL__/g, uriProductList+"?category_id=" + parent_id);
                }
                ////addFilter = addFilter.replace(/__URL__/g, uriProductList+"?category_id=" + parent_id);
                $('#'+parent_id).parent().parent().parent().find('.ec-itemNav__subnavThird').find('.ec-itemNav__subnavList').append(addFilter);
            }
        }
    };

    // カテゴリの階層を判断し、HeadingTextを更新
    var updateCategoryHeading = function(categoryId, categoryName) {
        var subnavContainer;

        // クリックされたカテゴリがどの階層かを判断
        if ($('#' + categoryId).closest('.ec-itemNav__subnavFirst').length > 0) {
            // クリックされた要素が第一階層直下の場合
            subnavContainer = $('#' + categoryId).closest('.ec-itemNav__navItem').find('.ec-itemNav__subnavSecond');
        } else if ($('#' + categoryId).closest('.ec-itemNav__subnavSecond').length > 0) {
            // クリックされた要素が第二階層直下の場合
            subnavContainer = $('#' + categoryId).closest('.ec-itemNav__navItem').find('.ec-itemNav__subnavThird');
        }

        // HeadingTextを更新
        if (subnavContainer && subnavContainer.length > 0) {
            subnavContainer.find('.ec-itemNav__subnavHeadingText').text(categoryName);
        }
    }

    // 絞込検索表示（SP表示）
    $(document).on('click', '.js-is-one-lower-category', function () {
        var ua = navigator.userAgent;
        var windowWidth = $(window).width();
        var categoryId = $(this).attr('id');
        var categoryName = $(this).text().trim();
        if ((ua.indexOf('iPhone') > 0 || ua.indexOf('Android') > 0) && ua.indexOf('Mobile') > 0) {

            // インジケーター表示
            showLoader('html');

            $('#js-refine-specification').addClass('is-hidden');

            $.ajax({
                url: uriTopSpecificationRefineDisplay,
                type: 'POST',
                data: {categoryId: categoryId},
            }).done(function (data) {
                $('#js-refine-specification').html(data.html);
                $('#js-refine-specification').removeClass('is-hidden');
                updateCategoryHeading(categoryId, categoryName);

                // インジケーター非表示
                hideLoader('html');
            }).fail(function (data) {
                alert(refine_search_message);
                // インジケーター非表示
                hideLoader('html');
            });
        } else if(windowWidth <= 768) {
            updateCategoryHeading(categoryId, categoryName);
        }
    });

    // 絞込検索表示（SP表示 Backする場合）
    $(document).on('click', '.ec-itemNav__subnavHeadingPrev', function () {

        var ua = navigator.userAgent;
        if ((ua.indexOf('iPhone') > 0 || ua.indexOf('Android') > 0) && ua.indexOf('Mobile') > 0) {

            // 第一階層でない場合
            if (!($(this).parent().parent().hasClass('ec-itemNav__subnavFirst'))) {

                // インジケーター表示
                showLoader('html');

                var categoryId = $(this).parent().parent().attr('id');

                $.ajax({
                    url: uriTopSpecificationRefineDisplay,
                    type: 'POST',
                    data: {categoryId: categoryId, isCategoryLow: true},
                }).done(function (data) {
                    $('#js-refine-specification').html(data.html);
                    $('#js-refine-specification').removeClass('is-hidden');
                    // インジケーター非表示
                    hideLoader('html');
                }).fail(function (data) {
                    alert(refine_search_message);
                    // インジケーター非表示
                    hideLoader('html');
                });
            }
        }
    });

    // 絞込検索仕様分類表示or絞込条件全てクリア（SP表示）
    $(document).on('click', '.js-check-specification-class , .js-ec-refine-search-btn' , function () {
        var ua = navigator.userAgent;
        if ((ua.indexOf('iPhone') > 0 || ua.indexOf('Android') > 0) && ua.indexOf('Mobile') > 0) {

            // インジケーター表示
            showLoader('html');

            // 自身が親の仕様であった場合のチェックボックスの操作
            if ($(this).hasClass('js-select_specification_id')) {
                var selectSpecificationClassIds = $(this).parents('.specification_tree').find('.js-select_specification_class_ids');
                if ($(this).prop('checked')) {
                    $.each(selectSpecificationClassIds, function (index, data) {
                        data.checked = true;
                    });
                } else {
                    $.each(selectSpecificationClassIds, function (index, data) {
                        data.checked = false;
                    });
                }
            } else {
                if (!$(this).prop('checked')) {
                    // 全てのチェックが外れている場合で親にspecification_idを保つ場合はそのチェックを外す
                    var selectSpecificationClassIds = $(this).parents('.specification_tree').find('.js-select_specification_class_ids');
                    var checked = false;
                    $.each(selectSpecificationClassIds, function (index, data) {
                        if (data.checked) {
                            checked = true;
                            return false;
                        }
                    });
                    if (!checked) {
                        var selectSpecification = $(this).parents('.specification_tree').find('.js-select_specification_id');
                        selectSpecification.prop('checked', false)
                    }
                }
            }

            // チェックがついている仕様分類を取得する
            var specificationClassIdList = [];
            var specificationIds = [];
            var checkSpecificationClass = $('.js-check-specification-class').length;
            for (var i = 0; i < checkSpecificationClass + 1; i++) {
                if ($('.ec-refineRole').find('[id=select_specification_class_id_' + i + ']:checked')) {
                    var specificationClassId = $('.ec-refineRole').find('[id=select_specification_class_id_' + i + ']:checked').val();
                    var specificationId = $('.ec-refineRole').find('[id=select_specification_class_id_' + i + ']:checked').next().val();

                    if (specificationClassId != undefined) {
                        specificationClassIdList.push(specificationClassId);
                        specificationIds.push(specificationId);
                    }
                }
            }

            // カテゴリIDを取得する
            var categoryId = $('#js-hidden-category-id').val();

            $.ajax({
                url: uriSpSpecificationDisplay,
                type: 'POST',
                data: {
                    categoryId: categoryId,
                    specificationClassIds: specificationClassIdList,
                    specificationIds: specificationIds
                },
            }).done(function (data) {
                $('.ec-refineRole').html(data.html);
                // インジケーター非表示
                hideLoader('html');
            }).fail(function (data) {
                alert(refine_search_message);
                // インジケーター非表示
                hideLoader('html');
            });
        }
    });

    // 仕様表示画面から移動時に絞込検索表示を非表示
    $(document).on('click', '.js-refine-specification-close , .ec-headerSearch__close', function () {
        $('#js-refine-specification').addClass('is-hidden');
    });

    // ヘッダー 製品カテゴリナビ カテゴリの動的追加
    $('.ec-itemNav__navList').each(function () {
        $('.ec-itemNav__navList').on({
            'click': function () {
                addSubnavSecondCategory($(this).attr("id"));
                $('.ec-itemNav__navList').on({
                    'click': function () {
                        addSubnavThirdCategory($(this).attr("id"));
                    }
                }, '.ec-itemNav__subnavSecond li.is-tree');
            }
        }, '.ec-itemNav__subnavFirst li.is-tree');
    }, '.ec-itemNav__subnav');

    // トップページのみスライダーを使用する
    if ($('#page_homepage').length > 0) {
        // slick
        $('.ec-sliderRole__slider').slick({
            infinite: true,
            dots: true,
            arrows: true,
            autoplay: true,
            speed: 800,
            variableWidth: true,
            centerMode: true,
            responsive: [
                {
                    breakpoint: 1440,
                    settings: {
                        variableWidth: false,
                        centerMode: false
                    }
                },
                {
                    breakpoint: 767,
                    settings: {
                        dots: true,
                        arrows: false,
                        variableWidth: false,
                        centerMode: false
                    }
                }
            ]
        });

        function sloganRoleListSlider(){
            if($(window).width() <= 767) {
                $('.ec-sloganRole__list').not('.slick-initialized').slick({
                    infinite: false,
                    dots: true,
                    arrows: true,
                    speed: 800
                });
            } else {
                $('.ec-sloganRole__list.slick-initialized').slick('unslick');
            }
        }
        sloganRoleListSlider();
        $(window).resize( function() {
            sloganRoleListSlider();
        });
    }

    /************************************ 検索BOX(PC/SP) **************************************/

        // 検索BOX
    var headerSearchInput = $('#ec-headerSearch__keywordInput');
    var headerSearchInputSp = $('#ec-headerSearch__keywordInput--sp');

    // 検索BOX リセットボタン
    $('#ec-headerSearch__keywordReset').on('click', function () {
        headerSearchInput.attr('value', '');
        headerSearchInputSp.attr('value', '');

        // リセット時に検索ボタンを制御
        $('.js-search-submit').addClass('is-pe-none');

        setTimeout(function () {
            if (localStorage.getItem('search_history_keyword')) {
                // 検索履歴保持（未入力時）を表示する
                $('#js-searchHistory-section-word').addClass('is-hidden');
                $('#js-searchTag-section').addClass('is-hidden');
                $('#js-searchHistory-section').addClass('is-hidden');
                $('#js-category-section').addClass('is-hidden');
                $('#js-item-section').addClass('is-hidden');
                $('#js-product-section').addClass('is-hidden');

                $('#ec-headerSearchCont').removeClass('is-hidden');
                $('#js-searchHistory-section-not-entered').removeClass('is-hidden');

                var ua = navigator.userAgent;
                if ((ua.indexOf('iPhone') > 0 || ua.indexOf('Android') > 0) && ua.indexOf('Mobile') > 0) {
                    // スマートフォン用処理
                    $('#js-sp-display-show-category').removeClass('is-hidden');
                } else if (ua.indexOf('iPad') > 0 || ua.indexOf('Android') > 0) {
                    // タブレット用処理
                    $('#js-sp-display-show-category').removeClass('is-hidden');
                } else {
                    // PC用処理
                    $('#js-sp-display-show-category').addClass('is-hidden');
                }
            } else {
                $('#ec-headerSearchCont').addClass('is-hidden');
            }
        }, timerSeconds);
    });

    // 検索BOX 初期表示時、検索ボタン制御
    if (headerSearchInput.val() || headerSearchInputSp.val()) {
        $('.js-search-submit').removeClass('is-pe-none');
    } else {
        $('.js-search-submit').addClass('is-pe-none');
    }

    // 検索BOX 入力（削除）時、検索ボタン制御（入力が文字だけの場合エンターできない制御）
    headerSearchInput.keyup(function () {
        headerSearchInputSp.val('');
        if (headerSearchInput.val().match(/^[ 　\r\n\t]*$/)) {
            $('.js-search-submit').addClass('is-pe-none');
        } else {
            if (headerSearchInput.val()) {
                $('.js-search-submit').removeClass('is-pe-none');
            } else {
                $('.js-search-submit').addClass('is-pe-none');
            }
        }
    });
    headerSearchInputSp.keyup(function () {
        headerSearchInput.val('');
        if (headerSearchInputSp.val().match(/^[ 　\r\n\t]*$/)) {
            $('.js-search-submit').addClass('is-pe-none');
        } else {
            if (headerSearchInputSp.val()) {
                $('.js-search-submit').removeClass('is-pe-none');
            } else {
                $('.js-search-submit').addClass('is-pe-none');
            }
        }
    });

    // エンターキー制御（文字が入力されていない場合or空白の場合エンターできない制御）
    headerSearchInput.keypress(function (e) {
        if (headerSearchInput.val().match(/^[ 　\r\n\t]*$/)) {
            if (e.which === 13) {
                return false;
            }
        } else {
            if (headerSearchInput.val()) {
                if (e.which === 13) {
                    return true;
                }
            } else {
                if (e.which === 13) {
                    return false;
                }
            }
        }
    });
    headerSearchInputSp.keypress(function (e) {
        if (headerSearchInputSp.val().match(/^[ 　\r\n\t]*$/)) {
            if (e.which === 13) {
                return false;
            }
        } else {
            if (headerSearchInputSp.val()) {
                if (e.which === 13) {
                    return true;
                }
            } else {
                if (e.which === 13) {
                    return false;
                }
            }
        }
    });

    /*********************************** 製品検索 *************************************/
    // 初期時（製品検索件数0の為）非表示
    $('#js-product-section').addClass('is-hidden');
    var isProductSearchWord = false;

    /**
     * 製品用DOM生成(PC)
     *
     * @param productInfo
     * @param analyticsParams
     */
    var createProductDomForPc = function (productInfo, analyticsParams) {
        var addProductUrlFirst = ('<a href="' + uriProductJsonDetailItem + analyticsParams + '" class="ec-headerSearchCont__suggestLink">' + productInfo['name'] + '</a>');
        // リプレイスで遷移先のアイテムIDを指定する（検索候補）
        addProductUrlFirst = addProductUrlFirst.replace('productJsonDataItemId', productInfo['item_id']);
        // リプレイスで遷移先の製品IDを指定する（検索候補）
        var addProductUrl = addProductUrlFirst.replace('productJsonDataProductId', productInfo['id']);

        // 完全一致すれば検索候補に追加する
        $('#js-add-product').append('<li class="js-displayed-product ec-productCategory__item ec-headerSearchCont__suggestItem">' + addProductUrl +
            '<input type="hidden" class="js-check-product" value="' + productInfo['id'] + '"></li>');

        // ディスクリプション(製品)
        var productDescription = productInfo['description'] ? productInfo['description'] : '';

        // リプレイスで遷移先のアイテムIDを指定する（画像付き）
        var productUrlImgFirst = ('<a href="' + uriProductJsonDetailItem + analyticsParams + '">' +
            '<img src="' + productInfo['img'] + '" alt="' + productDescription + '"></a>').replace('productJsonDataItemId', productInfo['item_id']);
        // リプレイスで遷移先の製品IDを指定する（画像付き）
        var productUrlImg = productUrlImgFirst.replace('productJsonDataProductId', productInfo['id']);

        // リプレイスで遷移先のアイテムIDを指定する（画像なし）
        var productUrlFirst = ('<a href="' + uriProductJsonDetailItem + analyticsParams + '">' + productInfo['name']
            + '</a>').replace('productJsonDataItemId', productInfo['item_id']);
        // リプレイスで遷移先の製品IDを指定する（画像なし）
        var productUrl = productUrlFirst.replace('productJsonDataProductId', productInfo['id']);

        // 型式
        var productModel = ('<li class="ec-productDetail__codeItem">' + history_type + productInfo['model'] + '</li>');
        // 製品コード
        var productCode = ('<li class="ec-productDetail__codeItem">' + history_product_code + productInfo['product_code'] + '</li>');
        // 製品価格
        var price = productInfo['price'];
        var taxIncPrice = parseInt(price) + (parseInt(price) * (10/100));
        var productPrice = ('<div class="ec-productDetail__price">' + parseInt(price).toLocaleString() + history_yen
            + tax_included_one_side_parentheses + taxIncPrice.toLocaleString() + history_yen_one_side_parentheses + '</div>');

        // 在庫区分によって製品在庫コメントのクラスを切り分ける
        var productStockComment = "";
        if (productInfo['stock'] == 1) {
            // 在庫ありの場合
            productStockComment = ('<span class="ec-label__status--thatDay">' + productInfo['comment'] + '</span>');
        } else if (productInfo['stock'] == 2) {
            // 予定在庫の場合
            productStockComment = ('<span class="ec-product__status--shipmentDate">' + productInfo['comment'] + '</span>');
        } else {
            // 標準納期の場合
            productStockComment = ('<span class="ec-product__status--shipmentDateLess">' + productInfo['comment'] + '</span>');
        }

        // カート遷移ボタン
        var cartButton = ('<button type="button" class="ec-blockBtn--action js-addCart js-addCartVal-product" data-cart-product="'
            + productInfo['id'] + '"><b aria-hidden="true" class="iconfont iconfont-shopping-cart"></b>' + history_add_to_cart + '</button>');

        // 製品候補表示箇所
        var product_price_comment = "";
        if (isLogined) {
            // 別途見積かの判断
            if (productInfo['separatelyEstimateCheck']) {
                product_price_comment = ('<div class="ec-productDetail__price">' + separately_estimate + '</div>') + productStockComment;
            } else {
                product_price_comment = productPrice + productStockComment;
            }
        }

        var productSideInner = '<div class="ec-productDetail__img js-show-product">' + productUrlImg + '</div>' +
            '<div class="ec-productDetail__body js-addCartItem"><div class="ec-productDetail__name">' + productUrl + '</div>' +
            '<ul class="ec-productDetail__codeList">' + productModel + productCode + '</ul>';
        if (productDescription) {
            productSideInner += '<div class="ec-productDetail__text">' + productDescription + '</div>';
        }
        productSideInner += product_price_comment;
        // カート追加ボタンの追加
        if ($('#addCartJs').length > 0) {
            productSideInner += '<div class="ec-productDetail__footer"><div class="ec-productDetail__amount"><span class="ec-productDetail__amountText">' + common_quantity + '</span>' +
                '<input type="number" class="ec-productDetail__amountInput js-addCartVal-quantity" value="1"></div>' + cartButton + '' + '</div>';
        }
        productSideInner += '</div><input type="hidden" class="js-check-product-side-item" value="' + productInfo['id'] + '">';

        $('#js-product-side').append(productSideInner);

        // 候補表示幅クラスの追加
        $('#js-product-section').addClass('is-code');
    };

    /**
     * 製品用DOM生成(SP)
     *
     * @param productInfo
     * @param analyticsParams
     */
    var createProductDomForSp = function (productInfo, analyticsParams) {
        // リプレイスで遷移先のアイテムIDを指定する（検索候補）
        var addProductUrlFirst = ('<a href="' + uriProductJsonDetailItem + analyticsParams + '" class="ec-headerSearchCont__suggestLink">'
            + productInfo['name'] + '</a>').replace('productJsonDataItemId', productInfo['item_id']);

        // リプレイスで遷移先の製品IDを指定する（検索候補）
        var addProductUrl = addProductUrlFirst.replace('productJsonDataProductId', productInfo['id']);

        // 完全一致すれば検索候補に追加する
        $('#js-add-product').append('<li class="js-displayed-product ec-productCategory__item ec-headerSearchCont__suggestItem">' + addProductUrl +
            '<input type="hidden" class="js-check-product" value="' + productInfo['id'] + '"></li>');

        // 候補表示幅クラスの追加
        $('#js-product-section').addClass('is-code');
    };

    /**
     * 製品情報を元にDOMを加工する
     *
     * @param product
     * @param isSp
     */
    var searchOfProduct = function (product, isSp) {
        // 検索にヒットしたかの判定を行う
        isProductSearchWord = false;

        // 入力値の取得
        var inputVal = headerSearchInput.val();
        if (isSp) {
            inputVal = headerSearchInputSp.val();
        }

        // google analytics
        var analyticsParams = "?keyword=" + inputVal + "&search=product";

        // 一致した製品でDOM生成
        if (isSp) {
            createProductDomForSp(product, analyticsParams);
        } else {
            createProductDomForPc(product, analyticsParams);
        }

        var displayProductLength = $('.js-displayed-product').length;
        if (displayProductLength == 1) {
            $('#js-product-section').removeClass('is-hidden');

            // 検索に該当するのでtrueを返す
            isProductSearchWord = true;

            // 完全一致すればカテゴリなどの表示は非表示にする
            if (isSp) {
                $('#js-sp-display-show-category').addClass('is-hidden');
            }
            $('#js-searchHistory-section-not-entered').addClass('is-hidden');
            $('#js-searchHistory-section-word').addClass('is-hidden');
            $('#js-searchTag-section').addClass('is-hidden');
            $('#js-searchHistory-section').addClass('is-hidden');
            $('#js-category-section').addClass('is-hidden');
            $('#js-item-section').addClass('is-hidden');
        } else {
            $('#js-product-section').addClass('is-hidden');
        }
    };

    /************************************ カテゴリ検索 **************************************/
    var categoryJsonData;
    // カテゴリに登録されたカテゴリ名/IDの取得(JSON形式)
    $.when(
        $.getJSON(uriCategoryJson, function (category) {
            if (category['id']) {
                categoryJsonData = category;
                addSubnavFirstCategory(category);
            } else {
                categoryJsonData = {};
                categoryJsonData['id'] = [];
                categoryJsonData['name'] = [];
                categoryJsonData['children_ids'] = [];
                categoryJsonData['children_files'] = [];
                categoryJsonData['img_file_s'] = [];
                categoryJsonData['img_file_m'] = [];
            }
        })
    );

    // 初期時（カテゴリ検索件数0の為）非表示
    $('#js-category-section').addClass('is-hidden');
    var isCategorySearchWord = false;

    var searchOfCategory = function (isSp) {
        // 検索にヒットしたかの判定を行う
        isCategorySearchWord = false;

        // 入力値の取得
        var inputVal = headerSearchInput.val();
        if (isSp) {
            inputVal = headerSearchInputSp.val();
        }

        if (inputVal.match(/^[ 　\r\n\t]*$/)) {
            return;
        }

        $('#js-category-section').removeClass('is-hidden');
        $('.js-displayed-category').remove();
        $('.js-category-delete').remove();

        var val = inputVal.split(/\s+/);
        var categoryLength = categoryJsonData["name"].length;
        var valLength = val.length;
        var analyticsParams = "&keyword=" + val + "&search=category";

        if (valLength > 1) {
            // 複数検索表示の場合
            var multipleSearchCount = 0;
            for (var i = 0; i < categoryLength; i++) {
                // 入力された複数の検索ワードの部分一致判定を行う
                var categoryMatch = 0;
                for (var j = 0; j < valLength; j++) {
                    if (categoryJsonData["name"][i].indexOf(val[j]) != -1) {
                        categoryMatch++;
                    }
                }
                // 入力された検索ワードと部分一致した数を比較する
                if (valLength == categoryMatch) {
                    if (isSp) {
                        // 全てが部分一致すれば検索候補に追加する
                        $('#js-add-category').append(
                            '<li class="js-displayed-category ec-productCategory__item">' +
                            '   <a href="' + uriProductList + '?category_id=' + categoryJsonData["id"][i] + analyticsParams + '" class="ec-productCategory__link">' +
                            '       <img src="' + categoryJsonData['img_file_m'][i] + '" alt="' + categoryJsonData["name"][i] + '">' + categoryJsonData["name"][i] +
                            '   </a>' +
                            '   <input type="hidden" class="js-check-category" value="' + categoryJsonData["id"][i] + '">' +
                            '</li>'
                        );
                    } else {
                        // カテゴリ検索候補を追加する
                        $(categoryJsonData['children_ids'][categoryJsonData["id"][i]]).each(function (index, value) {
                            var childCategoryImgSrc = categoryJsonData['children_files'][categoryJsonData["id"][i]] ? categoryJsonData['children_files'][categoryJsonData["id"][i]][index] : '';
                            var childCategoryName = categoryJsonData['children_names'][categoryJsonData["id"][i]] ? categoryJsonData['children_names'][categoryJsonData["id"][i]][index] : '';
                            $('#js-add-category-side-list').append(
                                '<li class="js-show-category-' + [multipleSearchCount] + ' js-category-delete ec-productCategory__sideItem">' +
                                '   <a href="' + uriProductList + '?category_id=' + categoryJsonData['children_ids'][categoryJsonData["id"][i]][index] + analyticsParams + '" class="ec-productCategory__link">' +
                                '       <img src="' + childCategoryImgSrc + '" alt="' + childCategoryName + '">' + childCategoryName +
                                '   </a>' +
                                '   <input type="hidden" class="js-check-category-side-item-' + [multipleSearchCount] + '" value="' + categoryJsonData["id"][i] + '">' +
                                '</li>'
                            );
                        });

                        // 追加した数をカウントする
                        multipleSearchCount++;

                        // 全てが部分一致すれば検索候補に追加する
                        $('#js-add-category').append('<li class="js-displayed-category ec-productCategory__item">' +
                            '<a href="' + uriProductList + '?category_id=' + categoryJsonData["id"][i] + analyticsParams + '" class="ec-productCategory__link">' + categoryJsonData["name"][i] + '</a>' +
                            '<input type="hidden" class="js-check-category" value="' + categoryJsonData["id"][i] + '"></li>');
                    }
                }
            }
        } else {
            // 単数検索表示の場合
            if (inputVal != '') {
                var soloSearchCount = 0;
                for (var i = 0; i < categoryLength; i++) {
                    if (categoryJsonData["name"][i].indexOf(val) != -1) {
                        if (isSp) {
                            // 部分一致すれば検索候補に追加する
                            $('#js-add-category').append('' +
                                '<li class="js-displayed-category ec-productCategory__item">' +
                                '   <a href="' + uriProductList + '?category_id=' + categoryJsonData["id"][i] + analyticsParams + '" class="ec-productCategory__link">' +
                                '       <img src="' + categoryJsonData['img_file_m'][i] + '" alt="' + categoryJsonData["name"][i] + '">' + categoryJsonData["name"][i] +
                                '   </a>' +
                                '   <input type="hidden" class="js-check-category" value="' + categoryJsonData["id"][i] + '">' +
                                '</li>'
                            );
                        } else {
                            // カテゴリ検索候補を追加する
                            $(categoryJsonData['children_ids'][categoryJsonData["id"][i]]).each(function (index, value) {
                                var imgSrc = categoryJsonData['children_files'][categoryJsonData["id"][i]] ? categoryJsonData['children_files'][categoryJsonData["id"][i]][index] : '';
                                var childCategoryName = categoryJsonData['children_names'][categoryJsonData["id"][i]] ? categoryJsonData['children_names'][categoryJsonData["id"][i]][index] : '';
                                $('#js-add-category-side-list').append(
                                    '<li class="js-show-category-' + [soloSearchCount] + ' js-category-delete ec-productCategory__sideItem">' +
                                    '   <a href="' + uriProductList + '?category_id=' + categoryJsonData['children_ids'][categoryJsonData["id"][i]][index] + analyticsParams + '" class="ec-productCategory__link">' +
                                    '       <img src="' + imgSrc + '" alt="' + childCategoryName + '">' + childCategoryName + '' +
                                    '   </a>' +
                                    '   <input type="hidden" class="js-check-category-side-item-' + [soloSearchCount] + '" value="' + categoryJsonData["id"][i] + '">' +
                                    '</li>'
                                );
                            });

                            // 追加した数をカウントする
                            soloSearchCount++;
                            // 部分一致すれば検索候補に追加する
                            $('#js-add-category').append('' +
                                '<li class="js-displayed-category ec-productCategory__item">' +
                                '   <a href="' + uriProductList + '?category_id=' + categoryJsonData["id"][i] + analyticsParams + '" class="ec-productCategory__link">' + categoryJsonData["name"][i] + '</a>' +
                                '   <input type="hidden" class="js-check-category" value="' + categoryJsonData["id"][i] + '">' +
                                '</li>'
                            );
                        }
                    }
                }
            }
        }

        var displayCategoryLength = $('.js-displayed-category').length;
        if (displayCategoryLength == 0 || displayCategoryLength > 5) {
            $('#js-category-section').addClass('is-hidden');
        } else {
            // 検索に該当するのでtrueを返す
            isCategorySearchWord = true;
        }
    };

    /************************** サブカテゴリ（アイテム）検索 *********************************/
    var itemJsonData;
    // サブカテゴリ（アイテム）に登録されたアイテム名/IDの取得(JSON形式)
    $.when(
        $.getJSON(uriItemJson, function (item) {
            if (item['id']) {
                itemJsonData = item;
            } else {
                itemJsonData = {};
                itemJsonData['id'] = {};
                itemJsonData['name'] = {};
                itemJsonData['id'] = {};
            }
        })
    );
    // 初期時（サブカテゴリ（アイテム）検索件数0の為）非表示
    $('#js-item-section').addClass('is-hidden');
    var isItemSearchWord = false;

    var searchOfSubCategoryItem = function (isSp) {
        // 検索にヒットしたかの判定を行う
        isItemSearchWord = false;

        // 入力値の取得
        var inputVal = headerSearchInput.val();
        if (isSp) {
            inputVal = headerSearchInputSp.val();
        }

        if (inputVal.match(/^[ 　\r\n\t]*$/)) {
            return;
        }

        $('#js-item-section').removeClass('is-hidden');
        $('.js-displayed-item').remove();
        $('.js-sub-category-delete').remove();

        var val = inputVal.split(/\s+/);
        var itemLength = itemJsonData["name"].length;
        var valLength = val.length;
        var analyticsParams = "keyword=" + val + "&search=item";

        if (valLength > 1) {
            // 複数検索表示の場合
            var multipleSearchCount = 0;
            for (var i = 0; i < itemLength; i++) {
                // 入力された複数の検索ワードの部分一致判定を行う
                var itemMatch = 0;
                for (var j = 0; j < valLength; j++) {
                    if (itemJsonData["name"][i].indexOf(val[j]) != -1) {
                        itemMatch++;
                    }
                }
                // 入力された検索ワードと部分一致した数を比較して、一致すれば検索候補に追加する
                if (valLength == itemMatch) {
                    var productDetailLink;
                    if (isSp) {
                        // リプレイスで遷移先のIDを指定する（画像付き）
                        productDetailLink = uriProductDetailItemJsonData.replace('itemJsonDataId', itemJsonData["id"][i]) + '?' + analyticsParams;
                        var transitionItemAndImg = ('<a href="' + productDetailLink + '" class="ec-productCategory__link">'
                            + '<img src="' + itemJsonData['img'][i] + '">' + itemJsonData["name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-item').append('<li class="js-displayed-item ec-productCategory__item">' + transitionItemAndImg + '' +
                            '<input type="hidden" class="js-check-sub-category" value="' + itemJsonData["id"][i] + '"></li>');
                    } else {
                        // リプレイスで遷移先のIDを指定する（画像付き）
                        productDetailLink = uriProductDetailItemJsonData.replace('itemJsonDataId', itemJsonData["id"][i]) + '?' + analyticsParams;
                        var transitionItemAndImg = ('<a href="' + productDetailLink + '" class="ec-productCategory__sideLink">'
                            + '<img src="' + itemJsonData['img'][i] + '" alt="' + itemJsonData["name"][i] + '">' + itemJsonData["name"][i] + '</a>');

                        // サブカテゴリ（アイテム）検索候補を追加する
                        $('#js-add-sub-category-side-list').append('<li class="js-show-sub-category-' + multipleSearchCount + ' js-sub-category-delete ec-productCategory__sideItem">' + transitionItemAndImg +
                            '<input type="hidden" class="js-check-sub-category-side-item-' + multipleSearchCount + '" value="' + itemJsonData["id"][i] + '"></li>');

                        // 追加した数をカウントする
                        multipleSearchCount++;

                        // リプレイスで遷移先のIDを指定する
                        productDetailLink = uriProductDetailItemJsonData.replace('itemJsonDataId', itemJsonData["id"][i]) + "?" + analyticsParams;
                        var transitionItem = ('<a href="' + productDetailLink + '" class="ec-productCategory__link">' + itemJsonData["name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-item').append('<li class="js-displayed-item ec-productCategory__item">' + transitionItem + '' +
                            '<input type="hidden" class="js-check-sub-category" value="' + itemJsonData["id"][i] + '"></li>');
                    }
                }
            }
        } else {
            // 単数検索表示の場合
            var soloSearchCount = 0;
            for (var i = 0; i < itemLength; i++) {
                // 部分一致判定をする
                if (itemJsonData["name"][i].indexOf(val) != -1) {
                    var productDetailLink;
                    if (isSp) {
                        // リプレイスで遷移先のIDを指定する（画像付き）
                        productDetailLink = uriProductDetailItemJsonData.replace('itemJsonDataId', itemJsonData["id"][i]) + '?' + analyticsParams;
                        var transitionItemAndImg = ('<a href="' + productDetailLink + '" class="ec-productCategory__link">'
                            + '<img src="' + itemJsonData['img'][i] + '">' + itemJsonData["name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-item').append('<li class="js-displayed-item ec-productCategory__item">' + transitionItemAndImg + '' +
                            '<input type="hidden" class="js-check-sub-category" value="' + itemJsonData["id"][i] + '"></li>');
                    } else {
                        // リプレイスで遷移先のIDを指定する（画像付き）
                        productDetailLink = uriProductDetailItemJsonData.replace('itemJsonDataId', itemJsonData["id"][i]) + '?' + analyticsParams;
                        var transitionItemAndImg = ('<a href="' + productDetailLink + '" class="ec-productCategory__sideLink">'
                            + '<img src="' + itemJsonData['img'][i] + '" alt="' + itemJsonData["name"][i] + '">' + itemJsonData["name"][i] + '</a>');

                        // サブカテゴリ（アイテム）検索候補を追加する
                        $('#js-add-sub-category-side-list').append('<li class="js-show-sub-category-' + soloSearchCount + ' js-sub-category-delete ec-productCategory__sideItem">' + transitionItemAndImg +
                            '<input type="hidden" class="js-check-sub-category-side-item-' + soloSearchCount + '" value="' + itemJsonData["id"][i] + '"></li>');

                        // 追加した数をカウントする
                        soloSearchCount++;

                        // リプレイスで遷移先のIDを指定する
                        productDetailLink = uriProductDetailItemJsonData.replace('itemJsonDataId', itemJsonData["id"][i]) + '?' + analyticsParams;
                        var transitionItem = ('<a href="' + productDetailLink + '" class="ec-productCategory__link">' + itemJsonData["name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-item').append('<li class="js-displayed-item ec-productCategory__item">' + transitionItem + '' +
                            '<input type="hidden" class="js-check-sub-category" value="' + itemJsonData["id"][i] + '"></li>');
                    }
                }
            }
        }
        var displayItemLength = $('.js-displayed-item').length;
        if (displayItemLength == 0 || displayItemLength > 5) {
            $('#js-item-section').addClass('is-hidden');
        } else {
            // 検索に該当するのでtrueを返す
            isItemSearchWord = true;
        }
    };

    /************************** 検索タグ検索 *********************************/
    var searchTagJsonData;
    // 検索タグに登録された検索タグ名/IDの取得(JSON形式)
    $.when(
        $.getJSON(uriSearchTagJson, function (searchTag) {
            if (searchTag['id']) {
                searchTagJsonData = searchTag;
            } else {
                searchTagJsonData = {};
                searchTagJsonData['id'] = [];
                searchTagJsonData['search_tag_name'] = [];
                searchTagJsonData['category_id'] = [];
                searchTagJsonData['category_name'] = [];
                searchTagJsonData['item_id'] = [];
                searchTagJsonData['item_name'] = [];
                searchTagJsonData['item_img'] = [];

            }
        })
    );
    // 初期時（検索タグ検索件数0の為）非表示
    $('#js-searchTag-section').addClass('is-hidden');

    var isSearchTagSearchWord = false;

    var searchOfSearchTag = function (isSp) {
        // 検索にヒットしたかの判定を行う
        isSearchTagSearchWord = false;

        // 入力値の取得
        var inputVal = headerSearchInput.val();
        if (isSp) {
            inputVal = headerSearchInputSp.val();
        }

        if (inputVal.match(/^[ 　\r\n\t]*$/)) {
            return;
        }

        $('#js-searchTag-section').removeClass('is-hidden');
        $('.js-displayed-searchTag').remove();
        $('.js-search-tag-category-delete').remove();
        $('.js-search-tag-sub-category-delete').remove();
        $('.js-search-history-delete').remove();
        var addSearchHistoryCount = false;
        var val = inputVal.split(/\s+/);
        var searchTagLength = searchTagJsonData["search_tag_name"].length;
        var valLength = val.length;
        var analyticsParams = "&keyword=" + val;
        var analyticsParamsKeyword = analyticsParams + "&search=keyword";
        var analyticsParamsCategory = analyticsParams + "&search=category";
        var analyticsParamsSubCategory = "?keyword=" + val + "&search=item";
        if (valLength > 1) {
            // 複数検索表示の場合
            var multipleSearchCountCategory = 0;
            var multipleSearchSubCountCategory = 0;
            for (var i = 0; i < searchTagLength; i++) {
                // 入力された複数の検索ワードの部分一致判定を行う
                var searchTagMatch = 0;
                for (var j = 0; j < valLength; j++) {
                    if (searchTagJsonData["search_tag_name"][i].indexOf(val[j]) != -1) {
                        searchTagMatch++;
                    }
                }

                // 入力された検索ワードと部分一致した数を比較して、一致すれば検索候補に追加する
                if (valLength == searchTagMatch) {
                    if (isSp) {
                        // リプレイスで遷移先のキーワード（検索タグ名）を指定する
                        var transitionItem = ('<a href="' + uriProductList + '?search_keywords--sp=&search_keywords=' + searchTagJsonData["search_tag_name"][i] + analyticsParams +
                            '" class="ec-headerSearchCont__suggestLink">' + searchTagJsonData["search_tag_name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-searchTag').append('<li class="js-displayed-searchTag ec-productCategory__item ec-headerSearchCont__suggestItem">' + transitionItem + '' +
                            '<input type="hidden" class="js-check-search-tag-category" value="' + searchTagJsonData["id"][i] + '"></li>');
                    } else {
                        // 検索タグ候補表示箇所
                        // カテゴリ
                        if (searchTagJsonData['category_id']) {
                            $(searchTagJsonData['category_id'][searchTagJsonData["id"][i]]).each(function (index) {
                                // カテゴリ追加
                                $('#js-add-search-tag-category-side-list').append('<li class="js-show-search-tag-category-' + [multipleSearchCountCategory] + ' js-search-tag-category-delete ec-productCategory__sideItem">' +
                                    '<a href="' + uriProductList + '?category_id=' + searchTagJsonData['category_id'][searchTagJsonData["id"][i]][index] + analyticsParamsCategory + '" class="ec-productCategory__link">' +
                                    '<img src="' + searchTagJsonData['category_img'][searchTagJsonData["id"][i]][index] + '" alt="' + searchTagJsonData['category_name'][searchTagJsonData["id"][i]][index] + '">' + searchTagJsonData['category_name'][searchTagJsonData["id"][i]][index] + '</a>' +
                                    '<input type="hidden" class="js-check-search-tag-category-side-item-' + [multipleSearchCountCategory] + '" value="' + searchTagJsonData["id"][i] + '"></li>');

                                // 追加した数をカウントする
                                multipleSearchCountCategory++;
                            });
                        }

                        // サブカテゴリ
                        if (searchTagJsonData['item_id']) {
                            $(searchTagJsonData['item_id'][searchTagJsonData["id"][i]]).each(function (index) {
                                // リプレイスで遷移先のIDを指定する（画像付き）
                                var transitionSearchTagSubCategoryAndImg = ('<a href="' + uriProductDetailSearchTag + analyticsParamsSubCategory + '" class="ec-productCategory__sideLink">'
                                    + '<img src="' + searchTagJsonData['item_img'][searchTagJsonData["id"][i]][index] + ' " alt="' + searchTagJsonData['item_name'][searchTagJsonData["id"][i]][index] + '">' + searchTagJsonData['item_name'][searchTagJsonData["id"][i]][index]
                                    + '</a>').replace('searchTagSubCategoryJsonData', searchTagJsonData['item_id'][searchTagJsonData["id"][i]][index]);

                                // サブカテゴリ（アイテム）追加
                                $('#js-add-search-tag-sub-category-side-list').append('<li class="js-show-search-tag-sub-category-' + [multipleSearchSubCountCategory] + ' js-search-tag-sub-category-delete ec-productCategory__sideItem">'
                                    + transitionSearchTagSubCategoryAndImg + '<input type="hidden" class="js-check-search-tag-sub-category-side-item-' + [multipleSearchSubCountCategory] + '" value="' + searchTagJsonData["id"][i] + '"></li>');

                                // 追加した数をカウントする
                                multipleSearchSubCountCategory++;
                            });
                        }

                        // リプレイスで遷移先のキーワード（検索タグ名）を指定する
                        var transitionItem = ('<a href="' + uriProductList + '?search_keywords--sp=&search_keywords=' + searchTagJsonData["search_tag_name"][i] + analyticsParamsKeyword + '" class="ec-headerSearchCont__suggestLink">' + searchTagJsonData["search_tag_name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-searchTag').append('<li class="js-displayed-searchTag ec-productCategory__item ec-headerSearchCont__suggestItem">' + transitionItem + '' +
                            '<input type="hidden" class="js-check-search-tag-category" value="' + searchTagJsonData["id"][i] + '"></li>');
                    }
                }
            }

            // 該当検索タグ件数が1件～5件までの場合に表示する（6件以上該当があれば、検索タグ表示を非表示する）
            var displaySearchTagLength = $('.js-displayed-searchTag').length;

            // 該当検索タグが5件以下の時検索履歴に該当するものがあれば追加で表示する
            if (displaySearchTagLength <= 5) {

                // 表示可能検索履歴数
                var viewableSearchHistoryCount = 5 - displaySearchTagLength;

                if (localStorage.getItem('search_history_keyword')) {
                    var searchHistoryKeywords = JSON.parse(localStorage.getItem('search_history_keyword')).reverse();
                    var addSearchHistoryCountLength = 0;

                    for (var io = 0; io < searchHistoryKeywords.length; io++) {
                        var searchHistoryCount = 0;
                        // 入力された複数の検索ワードの部分一致判定を行う
                        for (var jo = 0; jo < valLength; jo++) {
                            if (searchHistoryKeywords[io].trim().indexOf(val[jo]) != -1) {
                                searchHistoryCount++;
                            }
                        }

                        if (valLength == searchHistoryCount) {
                            addSearchHistoryCountLength++;

                            // 検索タグと検索履歴の表示件数が5件以下であれば追加する
                            if (viewableSearchHistoryCount >= addSearchHistoryCountLength) {
                                addSearchHistoryCount = true;

                                if (isSp) {
                                    $('#js-add-search-history').append('<li class="ec-headerSearchCont__suggestItem js-search-history-delete">' +
                                        '<a href="' + uriProductList + '?search_keywords--sp=&search_keywords=' + searchHistoryKeywords[io] + analyticsParams + '" class="ec-headerSearchCont__suggestLink">' +
                                        '<b aria-hidden="true" class="iconfont iconfont-clock-history"></b>' + searchHistoryKeywords[io] + '</a></li>');
                                } else {
                                    $('#js-add-search-history').append('<li class="ec-headerSearchCont__suggestItem js-search-history-delete">' +
                                        '<a href="' + uriProductList + '?search_keywords--sp=&search_keywords=' + searchHistoryKeywords[io] +
                                        analyticsParamsKeyword + '" class="ec-headerSearchCont__suggestLink">' +
                                        '<b aria-hidden="true" class="iconfont iconfont-clock-history"></b>' + searchHistoryKeywords[io] + '</a></li>');
                                }
                            }
                        }
                    }
                }
            }
        } else {
            // 単数検索表示の場合
            var soloSearchCountCategory = 0;
            var soloSearchCountSubCategory = 0;
            for (var i = 0; i < searchTagLength; i++) {
                // 部分一致判定をする
                if (searchTagJsonData["search_tag_name"][i].indexOf(val) != -1) {
                    var productListLink;
                    if (isSp) {
                        // リプレイスで遷移先のキーワード（検索タグ名）を指定する
                        productListLink = uriProductList + '?search_keywords--sp=&search_keywords=' + searchTagJsonData["search_tag_name"][i] + analyticsParamsKeyword;
                        var transitionItem = ('<a href="' + productListLink + '" class="ec-headerSearchCont__suggestLink">' + searchTagJsonData["search_tag_name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-searchTag').append('<li class="js-displayed-searchTag ec-productCategory__item ec-headerSearchCont__suggestItem">' + transitionItem + '' +
                            '<input type="hidden" class="js-check-search-tag-category" value="' + searchTagJsonData["id"][i] + '"></li>');
                    } else {
                        // 検索タグ候補表示箇所
                        // カテゴリ
                        if (searchTagJsonData['category_id']) {
                            $(searchTagJsonData['category_id'][searchTagJsonData["id"][i]]).each(function (index) {
                                // カテゴリ追加
                                productListLink = uriProductList + '?category_id=' + searchTagJsonData['category_id'][searchTagJsonData["id"][i]][index] + analyticsParamsCategory;
                                $('#js-add-search-tag-category-side-list').append('<li class="js-show-search-tag-category-' + [soloSearchCountCategory] + ' js-search-tag-category-delete ec-productCategory__sideItem">' +
                                    '<a href="' + productListLink + '" class="ec-productCategory__link">' +
                                    '<img src="' + searchTagJsonData['category_img'][searchTagJsonData["id"][i]][index] + '" alt="' + searchTagJsonData['category_name'][searchTagJsonData["id"][i]][index] + '">' + searchTagJsonData['category_name'][searchTagJsonData["id"][i]][index] + '</a>' +
                                    '<input type="hidden" class="js-check-search-tag-category-side-item-' + [soloSearchCountCategory] + '" value="' + searchTagJsonData["id"][i] + '"></li>');

                                // 追加した数をカウントする
                                soloSearchCountCategory++;
                            });
                        }

                        // サブカテゴリ
                        if (searchTagJsonData['item_id']) {
                            $(searchTagJsonData['item_id'][searchTagJsonData["id"][i]]).each(function (index) {
                                // リプレイスで遷移先のIDを指定する（画像付き）
                                var transitionSearchTagSubCategoryAndImg = ('<a href="' + uriProductDetailSearchTag + analyticsParamsSubCategory + '" class="ec-productCategory__sideLink">'
                                    + '<img src="' + searchTagJsonData['item_img'][searchTagJsonData["id"][i]][index] + '" alt="' + searchTagJsonData['item_name'][searchTagJsonData["id"][i]][index] + '">' + searchTagJsonData['item_name'][searchTagJsonData["id"][i]][index]
                                    + '</a>').replace('searchTagSubCategoryJsonData', searchTagJsonData['item_id'][searchTagJsonData["id"][i]][index]);

                                // サブカテゴリ（アイテム）追加
                                $('#js-add-search-tag-sub-category-side-list').append('<li class="js-show-search-tag-sub-category-' + [soloSearchCountSubCategory] + ' js-search-tag-sub-category-delete ec-productCategory__sideItem">'
                                    + transitionSearchTagSubCategoryAndImg + '<input type="hidden" class="js-check-search-tag-sub-category-side-item-' + [soloSearchCountSubCategory] + '" value="' + searchTagJsonData["id"][i] + '"></li>');

                                // 追加した数をカウントする
                                soloSearchCountSubCategory++;
                            });
                        }
                        // リプレイスで遷移先のキーワード（検索タグ名）を指定する
                        productListLink = uriProductList + '?search_keywords--sp=&search_keywords=' + searchTagJsonData["search_tag_name"][i] + analyticsParamsKeyword;
                        var transitionItem = ('<a href="' + productListLink + '" class="ec-headerSearchCont__suggestLink">' + searchTagJsonData["search_tag_name"][i] + '</a>');

                        // 検索候補に追加する
                        $('#js-add-searchTag').append('<li class="js-displayed-searchTag ec-productCategory__item ec-headerSearchCont__suggestItem">' + transitionItem + '' +
                            '<input type="hidden" class="js-check-search-tag-category" value="' + searchTagJsonData["id"][i] + '"></li>');
                    }

                }
            }

            // 該当検索タグ件数が1件～5件までの場合に表示する（6件以上該当があれば、検索タグ表示を非表示する）
            var displaySearchTagLength = $('.js-displayed-searchTag').length;

            // 該当検索タグが5件以下の時検索履歴に該当するものがあれば追加で表示する
            if (displaySearchTagLength <= 5) {
                // 表示可能検索履歴数
                var viewableSearchHistoryCount = 5 - displaySearchTagLength;

                if (localStorage.getItem('search_history_keyword')) {
                    var searchHistoryKeywords = JSON.parse(localStorage.getItem('search_history_keyword')).reverse();

                    var searchHistoryCount = 0;
                    for (var io = 0; io < searchHistoryKeywords.length; io++) {
                        // 入力された複数の検索ワードの部分一致判定を行う
                        for (var jo = 0; jo < valLength; jo++) {

                            if (searchHistoryKeywords[io].indexOf(val[jo]) != -1) {
                                searchHistoryCount++;

                                // 検索タグと検索履歴の表示件数が5件以下であれば追加する
                                if (viewableSearchHistoryCount >= searchHistoryCount) {
                                    addSearchHistoryCount = true;

                                    if (isSp) {
                                        $('#js-add-search-history').append('<li class="ec-headerSearchCont__suggestItem js-search-history-delete">' +
                                            '<a href="' + uriProductList + '?search_keywords--sp=&search_keywords=' + searchHistoryKeywords[io] + analyticsParams + '" class="ec-headerSearchCont__suggestLink">' +
                                            '<b aria-hidden="true" class="iconfont iconfont-clock-history"></b>' + searchHistoryKeywords[io] + '</a></li>');
                                    } else {
                                        $('#js-add-search-history').append('<li class="ec-headerSearchCont__suggestItem js-search-history-delete">' +
                                            '<a href="' + uriProductList + '?search_keywords--sp=&search_keywords=' + searchHistoryKeywords[io] + analyticsParamsKeyword + '" class="ec-headerSearchCont__suggestLink">' +
                                            '<b aria-hidden="true" class="iconfont iconfont-clock-history"></b>' + searchHistoryKeywords[io] + '</a></li>');
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        if (displaySearchTagLength == 0 || displaySearchTagLength > 5) {
            $('#js-searchTag-section').addClass('is-hidden');
        } else {
            // 検索に該当するのでtrueを返す
            isSearchTagSearchWord = true;
        }
        if (addSearchHistoryCount == true) {
            $('#js-searchTag-section').removeClass('is-hidden');
            isSearchTagSearchWord = true;
        }
    };

    /************************** 検索結果判定 *********************************/
    var judgeSearchResult = function (isSp) {
        // 入力値の取得
        var inputVal = headerSearchInput.val();
        if (isSp) {
            inputVal = headerSearchInputSp.val();
        }

        if (inputVal.length == 0) {
            // 入力が0文字の場合は、検索候補を非表示にする
            $('#js-product-section').addClass('is-hidden');
            $('#js-category-section').addClass('is-hidden');
            $('#js-item-section').addClass('is-hidden');
            $('#js-searchTag-section').addClass('is-hidden');
            $('#js-searchHistory-section').addClass('is-hidden');
            $('#js-searchHistory-section-word').addClass('is-hidden');
            $('#ec-headerSearchCont').addClass('is-hidden');

            if (localStorage.getItem('search_history_keyword')) {
                // 検索履歴保持（未入力時）を表示する
                $('#js-searchHistory-section-not-entered').removeClass('is-hidden');
                if (isSp) {
                    $('#js-sp-display-show-category').removeClass('is-hidden');
                }
                $('#ec-headerSearchCont').removeClass('is-hidden');
            }
        } else {
            // 入力があれば、文言を表示する
            if (isSearchTagSearchWord == true) {
                if (isProductSearchWord == false) {
                    $('#js-searchHistory-section-word').removeClass('is-hidden');
                    $('#js-searchHistory-section').removeClass('is-hidden');
                }
            } else {
                $('#js-searchHistory-section-word').addClass('is-hidden');
                $('#js-searchHistory-section').addClass('is-hidden');
            }

            // 入力があれば、検索履歴保持を非表示にする
            $('#js-searchHistory-section-not-entered').addClass('is-hidden');
            if (isSp) {
                $('#js-sp-display-show-category').addClass('is-hidden');
            }

            // カテゴリ表示、アイテム表示の時不要クラスがあるため、追加削除
            if (isProductSearchWord == false && isCategorySearchWord == true && isItemSearchWord == false && isSearchTagSearchWord == false) {
                $('#js-category-section').removeClass('ec-productCategory');
            } else if (isProductSearchWord == false && isCategorySearchWord == false && isItemSearchWord == true && isSearchTagSearchWord == false) {
                $('#js-item-section').removeClass('ec-productCategory');
            } else if (isProductSearchWord == false && isCategorySearchWord == true && isItemSearchWord == true && isSearchTagSearchWord == false) {
                $('#js-category-section').removeClass('ec-productCategory');
            } else {
                $('#js-category-section').addClass('ec-productCategory');
                $('#js-item-section').addClass('ec-productCategory');
            }

            // カテゴリ・アイテム・製品・検索タグで検索結果が無い場合
            if (isProductSearchWord == false && isCategorySearchWord == false && isItemSearchWord == false && isSearchTagSearchWord == false) {
                $('#ec-headerSearchCont').addClass('is-hidden');
            } else {
                // 検索結果がある場合
                $('#ec-headerSearchCont').removeClass('is-hidden');
            }
        }
    };

    /************************** 端末毎のクラス追加・削除 *********************************/
    var addClassForDevice = function (isSp) {
        var ua = navigator.userAgent;
        if ((ua.indexOf('iPhone') > 0 || ua.indexOf('Android') > 0) && ua.indexOf('Mobile') > 0) {
            // スマートフォン用処理
            $('.js-displayed-searchTag').removeClass('ec-productCategory__item');
            $('.js-displayed-product').removeClass('ec-productCategory__item');
            if (!isSp) {
                $('#js-sp-display-show-category').removeClass('is-hidden');
            }
        } else if (ua.indexOf('iPad') > 0 || ua.indexOf('Android') > 0) {
            // タブレット用処理
            $('.js-displayed-searchTag').removeClass('ec-productCategory__item');
            $('.js-displayed-product').removeClass('ec-productCategory__item');
            if (!isSp) {
                $('#js-sp-display-show-category').removeClass('is-hidden');
            }
        } else {
            // PC用処理
            $('.js-displayed-searchTag').addClass('ec-productCategory__item');
            $('.js-displayed-product').addClass('ec-productCategory__item');
            if (!isSp) {
                $('#js-sp-display-show-category').addClass('is-hidden');
            }
        }
    };

    /************************** 検索履歴（未入力時）追加 *********************************/
    // 検索履歴取得
    setTimeout(function () {
        if (localStorage.getItem('search_history_keyword') && localStorage.getItem('search_history_keyword') != "") {
            var searchHistoryKeywords = JSON.parse(localStorage.getItem('search_history_keyword')).reverse();

            searchHistoryKeywords.forEach(function (value, index) {
                if (index < 10) {
                    $('#js-add-search-history-not-entered').append('<li class="ec-headerSearchCont__suggestItem">' +
                        '<a href="' + uriProductList + '?search_keywords--sp=&search_keywords=' + value + '&keyword=' + value + '" class="ec-headerSearchCont__suggestLink">' +
                        '<b aria-hidden="true" class="iconfont iconfont-clock-history"></b>' + value + '</a></li>');
                }
            })
        }
    }, timerSeconds);

    /************************************ 検索(PC/SP共通) **************************************/
    // 最初に検索候補表示を非表示する
    $('#js-product-section').addClass('is-hidden');
    $('#js-category-section').addClass('is-hidden');
    $('#js-item-section').addClass('is-hidden');
    $('#js-searchTag-section').addClass('is-hidden');
    $('#js-searchHistory-section').addClass('is-hidden');
    $('#js-searchHistory-section-word').addClass('is-hidden');

    setTimeout(function () {
        if (!localStorage.getItem('search_history_keyword')) {
            // 検索履歴候補が無ければ、検索履歴保持を非表示にする
            $('#ec-headerSearchCont').addClass('is-hidden');
        } else {
            // 検索履歴候補があれば、候補全体を表示にする
            $('#ec-headerSearchCont').removeClass('is-hidden');
        }
    }, timerSeconds);

    // SP版のみ表示箇所を表示にする
    var ua = navigator.userAgent;
    if ((ua.indexOf('iPhone') > 0 || ua.indexOf('Android') > 0) && ua.indexOf('Mobile') > 0) {
        // スマートフォン用処理
        $('#js-sp-display-show-category').removeClass('is-hidden');
    } else if (ua.indexOf('iPad') > 0 || ua.indexOf('Android') > 0) {
        // タブレット用処理
        $('#js-sp-display-show-category').removeClass('is-hidden');
    } else {
        // PC用処理
        $('#js-sp-display-show-category').addClass('is-hidden');
    }

    var addActiveClassToHtml = function () {
        $('html').removeClass('is-active__ec-headerSearch');
        $('html').addClass('is-active__ec-headerSearch');
        if (window.matchMedia('(max-width: 767px)').matches) {
            var headerSearchH = $(window).height();
            $('html.is-active__ec-headerSearch').height(headerSearchH + 73);
            $('html.is-active__ec-headerSearch body').height(headerSearchH + 73);
        }
    };

    // ヘッダー 検索Inputクリックでhtmlに.is-active__ec-headerSearchを付与
    $('#ec-headerSearch__keywordInput,#ec-headerSearch__keywordInput--sp,#ec-headerRole__search').on('click', function () {
        addActiveClassToHtml();
    });
    $('#ec-headerSearch__close').on('click', function () {
        $('html').removeClass('is-active__ec-headerSearch');
        $('html').removeClass('is-active__ec-itemNav');
        $('.ec-itemNav__navItemInner').removeClass('is-active');
        $('.ec-itemNav__navItemInner').removeAttr('style');
        $('.ec-itemNav__subnavSecond').removeClass('is-active');
        $('.ec-itemNav__subnavThird').removeClass('is-active');
        $('html').removeAttr('style');
    });
    $('.ec-itemNav').hover(
        function () {
            $('html').removeClass('is-active__ec-headerSearch');
            $('#ec-headerSearch__keywordInput,#ec-headerSearch__keywordInput--sp,#ec-headerRole__search').blur();
        }
    );
    $(document).on('click', function (e) {
        if (headerSearchInput.css('display') != 'none') {
            if (!$(e.target).closest('#ec-headerSearch__keywordInput,#ec-headerSearchCont__side').length) {
                $('html').removeClass('is-active__ec-headerSearch');
            }
        }
    });

    // ヘッダー 検索Inputに1文字以上入力されたら#ec-headerSearchContと#ec-headerSearch__keywordResetに.is-activeを付与
    if ($("#ec-headerSearch__keywordInput,#ec-headerSearch__keywordInput--sp").val().length == 0) {
        $('#ec-headerSearchCont').removeClass('is-active');
        $('#ec-headerSearch__keywordReset').removeClass('is-active');
    } else {
        $('#ec-headerSearchCont').addClass('is-active');
        $('#ec-headerSearch__keywordReset').addClass('is-active');
    }
    $("#ec-headerSearch__keywordInput,#ec-headerSearch__keywordInput--sp").on("keydown keyup keypress change", function () {
        if ($(this).val().length < 1) {
            $('#ec-headerSearchCont').removeClass('is-active');
            $('#ec-headerSearch__keywordReset').removeClass('is-active');
        } else {
            $('#ec-headerSearchCont').addClass('is-active');
            $('#ec-headerSearch__keywordReset').addClass('is-active');
        }
    });

    // ヘッダー 検索Input クリアボタン
    $('#ec-headerSearch__keywordReset').on('click', function () {
        if ($(this).hasClass('is-active')) {
            $(this).removeClass('is-active');
        } else {
            $(this).addClass('is-active');
        }
    });

    var initialiseSearchResult = function () {
        $('#ec-headerSearch').removeClass('is-active');
        $('#ec-headerSearchCont__side').removeClass('is-active');

        // 製品の検索結果を初期化
        $('.js-displayed-product').remove();
        $('#js-product-section').removeClass('is-code');
        $('#js-product-side').children().remove();
        // カテゴリーの検索結果を初期化
        $('#js-category-section').addClass('is-hidden');
        $('.js-displayed-category').remove();
        $('.js-category-delete').remove();
        // サブカテゴリー（アイテム）の検索結果を初期化
        $('#js-item-section').addClass('is-hidden');
        $('.js-displayed-item').remove();
        $('.js-sub-category-delete').remove();
        // 検索タグの検索結果を初期化
        $('#js-searchTag-section').addClass('is-hidden');
        $('.js-displayed-searchTag').remove();
        $('.js-search-tag-category-delete').remove();
        $('.js-search-tag-sub-category-delete').remove();
        $('.js-search-history-delete').remove();
        $('#js-searchHistory-section').addClass('is-hidden');
        $('#js-searchHistory-section-word').addClass('is-hidden');
    };

    /*************************************** PC検索 *****************************************/
    var inputStack = [];
    $(document).on('keyup', '#ec-headerSearch__keywordInput', function () {
        inputStack.push(1);
        setTimeout(function() {
            inputStack.pop();
            if (inputStack.length != 0) {
                return;
            }
            inputStack = [];

            initialiseSearchResult();

            var requestParam = {
                'keyword': $('#ec-headerSearch__keywordInput').val()
            };
            $.ajax({
                url: uriProductSearch,
                type: 'POST',
                data: requestParam,
                dataType: 'json',
            }).done(function (ret) {
                // 一致する製品が存在する場合
                if (ret.result) {
                    searchOfProduct(ret.data, false);
                } else {
                    isProductSearchWord = false;
                    // カテゴリーの検索
                    searchOfCategory(false);
                    // サブカテゴリー（アイテム）の検索
                    searchOfSubCategoryItem(false);
                    // 検索タグの検索
                    searchOfSearchTag(false);
                }
                // 検索判定
                judgeSearchResult(false);
                // デバイスごとに必要なクラスを追加
                addClassForDevice(false);
                keywordInputKeyUp();
                addActiveClassToHtml();
                $('#ec-headerSearch').removeClass('is-active');
                $('#ec-headerSearchCont__side').removeClass('is-active');
            }).fail(function (data) {
            }).always(function () {
            });
        }, searchRunSeconds);
    });

    /************************** SP版検索 *********************************/
    var inputStackSp = [];
    $(document).on('keyup compositionend', '#ec-headerSearch__keywordInput--sp', function () {
        inputStackSp.push(1);
        // 入力が終了して1秒経過したら、検索候補を表示する
        setTimeout(function(){
            inputStackSp.pop();
            if (inputStackSp.length != 0) {
                return;
            }
            inputStackSp = [];

            initialiseSearchResult();

            var requestParam = {
                'keyword': $('#ec-headerSearch__keywordInput--sp').val()
            };
            $.ajax({
                url: uriProductSearch,
                type: 'POST',
                data: requestParam,
                dataType: 'json',
            }).done(function (ret) {
                // 一致する製品が存在する場合
                if (ret.result) {
                    searchOfProduct(ret.data, true);
                } else {
                    isProductSearchWord = false;
                    // カテゴリーの検索
                    searchOfCategory(true);
                    // サブカテゴリー（アイテム）の検索
                    searchOfSubCategoryItem(true);
                    // 検索タグの検索
                    searchOfSearchTag(true);
                }
                // 検索判定
                judgeSearchResult(true);
                // デバイスごとに必要なクラスを追加
                addClassForDevice(true);
                addActiveClassToHtml();
            }).fail(function (data) {
            }).always(function () {
            });
        }, searchRunSeconds)
    });

    var keywordInputKeyUp = function() {
        // 検索表示箇所の高さ初期化（マウスオーバー前）
        $('#ec-headerSearchCont__side').css('min-height', '');
        $('#ec-headerSearchCont').css('min-height', '');

        // ヘッダー 検索候補内 製品カテゴリにマウスオーバー時に.is-active付与
        $('.ec-productCategory__item').on({
            'mouseenter': function () {
                $('.ec-productCategory__item').removeClass('is-active');
                $('#ec-headerSearch').removeClass('is-active');
                $(this).addClass('is-active');
                $('#ec-headerSearch').addClass('is-active');

                // カテゴリ件数をリセットする
                $('#js-display-category-count').remove();
                // マウスオーバーされたときのカテゴリＩＤ
                var mouseOverCategoryId = $(this).children(".js-check-category").val();
                // マウスオーバー時のカテゴリ候補の数をカウントする
                var mouseOverCategory = $('.js-displayed-category').length;

                // サブカテゴリ（アイテム）件数をリセットする
                $('#js-display-sub-category-count').remove();
                // マウスオーバーされたときのサブカテゴリ（アイテム）ＩＤ
                var mouseOverSubCategoryId = $(this).children(".js-check-sub-category").val();
                // マウスオーバー時のサブカテゴリ（アイテム）候補の数をカウントする
                var mouseOverSubCategory = $('.js-displayed-item').length;

                // 検索タグ（カテゴリ）件数をリセットする
                $('#js-display-search-tag-category-count').remove();
                // マウスオーバーされたときの検索タグＩＤ（カテゴリ）
                var mouseOverSearchTagCategoryId = $(this).children(".js-check-search-tag-category").val();
                // マウスオーバー時の検索タグ（カテゴリ）候補の数をカウントする
                var mouseOverSearchTagCategory = $('.js-search-tag-category-delete').length;

                // 検索タグ（サブカテゴリ）件数をリセットする
                $('#js-display-search-tag-sub-category-count').remove();
                // マウスオーバーされたときの検索タグＩＤ（サブカテゴリ）
                var mouseOverSearchTagSubCategoryId = $(this).children(".js-check-search-tag-category").val();
                // マウスオーバー時の検索タグ（サブカテゴリ）候補の数をカウントする
                var mouseOverSearchTagSubCategory = $('.js-search-tag-sub-category-delete').length;

                // マウスオーバーされたときの製品ＩＤ
                var mouseOverProductId = $(this).children(".js-check-product").val();
                // マウスオーバー時の製品候補の数をカウントする
                var mouseOverProduct = $('.js-displayed-product').length;

                /********** カテゴリ **********/

                if ($(this).children(".js-check-category").val() != undefined) {

                    // 表示しない箇所を非表示にかつCSSを削除する
                    $("#js-sub-category-side").addClass('is-hidden');
                    $("#js-sub-category-side").removeClass('ec-productCategory__side');
                    $("#js-search-tag-category-side").addClass('is-hidden');
                    $("#js-search-tag-category-side").removeClass('ec-productCategory__side');
                    $("#js-search-tag-sub-category-side").addClass('is-hidden');
                    $("#js-search-tag-sub-category-side").removeClass('ec-productCategory__side');
                    $("#js-product-side").addClass('is-hidden');
                    $("#js-product-side").removeClass('ec-productCategory__side');

                    // 表示されているカテゴリ検索候補の表示非表示を判定する
                    var displayCategoryCount = 0;
                    for (var i = 0; i < mouseOverCategory; i++) {

                        // カテゴリ検索候補のIDを取得する
                        var searchCategoryId = $('.js-check-category-side-item-' + i + ' ').val();

                        if (mouseOverCategoryId == searchCategoryId) {

                            // 表示する箇所を表示かつCSSを追加する
                            $("#ec-headerSearchCont__side").removeClass('is-hidden');
                            $("#js-category-side").removeClass('is-hidden');
                            $("#js-category-side").addClass('ec-productCategory__side');

                            // show
                            $(".js-show-category-" + i + "").removeClass('is-hidden');
                            $(".js-show-category-" + i + "").addClass('js-displayed-category-side-item');
                            displayCategoryCount++;
                        } else {
                            // hide
                            $(".js-show-category-" + i + "").addClass('is-hidden');
                            $(".js-show-category-" + i + "").removeClass('js-displayed-category-side-item');
                        }

                        // 1件も検索候補が無い場合非表示
                        if (displayCategoryCount == 0) {
                            $("#js-category-side").addClass('is-hidden');
                        }
                    }
                    // カテゴリ件数の取得
                    var displayCategorySideItemCount = $('.js-displayed-category-side-item').length;
                    // カテゴリ件数を表示する
                    $('#js-category-side-heading').append('<span id="js-display-category-count">' +
                        '(' + displayCategorySideItemCount + '件)</span>');
                }

                /********** サブカテゴリ（アイテム） **********/

                // 表示されているサブカテゴリ検索候補の表示非表示を判定する

                if ($(this).children(".js-check-sub-category").val() != undefined) {

                    // 表示しない箇所を非表示にかつCSSを削除する
                    $("#js-category-side").addClass('is-hidden');
                    $("#js-category-side").removeClass('ec-productCategory__side');
                    $("#js-search-tag-category-side").addClass('is-hidden');
                    $("#js-search-tag-category-side").removeClass('ec-productCategory__side');
                    $("#js-search-tag-sub-category-side").addClass('is-hidden');
                    $("#js-search-tag-sub-category-side").removeClass('ec-productCategory__side');
                    $("#js-product-side").addClass('is-hidden');
                    $("#js-product-side").removeClass('ec-productCategory__side');

                    var displaySubCategoryCount = 0;
                    for (var i = 0; i < mouseOverSubCategory; i++) {

                        // サブカテゴリ（アイテム）検索候補のIDを取得する
                        var searchCategoryId = $('.js-check-sub-category-side-item-' + i + ' ').val();

                        if (mouseOverSubCategoryId == searchCategoryId) {

                            // 表示する箇所を表示かつCSSを追加する
                            $("#ec-headerSearchCont__side").removeClass('is-hidden');
                            $("#js-sub-category-side").removeClass('is-hidden');
                            $("#js-sub-category-side").addClass('ec-productCategory__side');

                            // show
                            $(".js-show-sub-category-" + i + "").removeClass('is-hidden');
                            $(".js-show-sub-category-" + i + "").addClass('js-displayed-sub-category-side-item');
                            displaySubCategoryCount++;
                        } else {
                            // hide
                            $(".js-show-sub-category-" + i + "").addClass('is-hidden');
                            $(".js-show-sub-category-" + i + "").removeClass('js-displayed-sub-category-side-item');
                        }

                        // 1件も検索候補が無い場合非表示
                        if (displaySubCategoryCount == 0) {
                            $("#js-sub-category-side").addClass('is-hidden');
                        }
                    }
                    // サブカテゴリ（アイテム）件数の取得
                    var displaySubCategorySideItemCount = $('.js-displayed-sub-category-side-item').length;
                    // サブカテゴリ（アイテム）件数を表示する
                    $('#js-sub-category-side-heading').append('<span id="js-display-sub-category-count">' +
                        '(' + displaySubCategorySideItemCount + '件)</span>');
                }

                /********** 検索タグ（検索タグ（カテゴリ）） **********/

                if ($(this).children(".js-check-search-tag-category").val() != undefined) {

                    // 表示しない箇所を非表示にかつCSSを削除する
                    $("#js-category-side").addClass('is-hidden');
                    $("#js-category-side").removeClass('ec-productCategory__side');
                    $("#js-sub-category-side").addClass('is-hidden');
                    $("#js-sub-category-side").removeClass('ec-productCategory__side');
                    $("#js-product-side").addClass('is-hidden');
                    $("#js-product-side").removeClass('ec-productCategory__side');

                    // 表示されている検索タグ（カテゴリ）検索候補の表示非表示を判定する
                    var displaySearchTagCategoryCount = 0;
                    for (var i = 0; i < mouseOverSearchTagCategory; i++) {

                        // 検索タグ（カテゴリ）検索候補のIDを取得する
                        var searchTagCategoryId = $('.js-check-search-tag-category-side-item-' + i + ' ').val();

                        if (mouseOverSearchTagCategoryId == searchTagCategoryId) {

                            // 表示する箇所を表示かつCSSを追加する
                            $("#ec-headerSearchCont__side").removeClass('is-hidden');
                            $("#js-search-tag-category-side").removeClass('is-hidden');
                            $("#js-search-tag-category-side").addClass('ec-productCategory__side');

                            // show
                            $(".js-show-search-tag-category-" + i + "").removeClass('is-hidden');
                            $(".js-show-search-tag-category-" + i + "").addClass('js-displayed-search-tag-category');
                            displaySearchTagCategoryCount++;
                        } else {
                            // hide
                            $(".js-show-search-tag-category-" + i + "").addClass('is-hidden');
                            $(".js-show-search-tag-category-" + i + "").removeClass('js-displayed-search-tag-category');
                        }

                        // 1件も検索候補が無い場合非表示
                        if (displaySearchTagCategoryCount == 0) {
                            $("#js-search-tag-category-side").addClass('is-hidden');
                        }
                    }
                    // 検索タグ（カテゴリ）件数の取得
                    var displaySearchTagCategorySideItemCount = $('.js-displayed-search-tag-category').length;
                    // 検索タグ（カテゴリ）件数を表示する
                    $('#js-search-tag-category-side-heading').append('<span id="js-display-search-tag-category-count">' +
                        '(' + displaySearchTagCategorySideItemCount + '件)</span>');
                }

                /********** 検索タグ（検索タグ（サブカテゴリ）） **********/

                if ($(this).children(".js-check-search-tag-category").val() != undefined) {

                    // 表示しない箇所を非表示にかつCSSを削除する
                    $("#js-category-side").addClass('is-hidden');
                    $("#js-category-side").removeClass('ec-productCategory__side');
                    $("#js-sub-category-side").addClass('is-hidden');
                    $("#js-sub-category-side").removeClass('ec-productCategory__side');
                    $("#js-product-side").addClass('is-hidden');
                    $("#js-product-side").removeClass('ec-productCategory__side');

                    // 表示されている検索タグ（サブカテゴリ）検索候補の表示非表示を判定する
                    var displaySearchTagSubCategoryCount = 0;
                    for (var i = 0; i < mouseOverSearchTagSubCategory; i++) {

                        // 検索タグ（サブカテゴリ）検索候補のIDを取得する
                        var searchTagSubCategoryId = $('.js-check-search-tag-sub-category-side-item-' + i + ' ').val();

                        if (mouseOverSearchTagSubCategoryId == searchTagSubCategoryId) {

                            // 表示する箇所を表示かつCSSを追加する
                            $("#ec-headerSearchCont__side").removeClass('is-hidden');
                            $("#js-search-tag-sub-category-side").removeClass('is-hidden');
                            $("#js-search-tag-sub-category-side").addClass('ec-productCategory__side');

                            // show
                            $(".js-show-search-tag-sub-category-" + i + "").removeClass('is-hidden');
                            $(".js-show-search-tag-sub-category-" + i + "").addClass('js-displayed-search-tag-sub-category');
                            displaySearchTagSubCategoryCount++;
                        } else {
                            // hide
                            $(".js-show-search-tag-sub-category-" + i + "").addClass('is-hidden');
                            $(".js-show-search-tag-sub-category-" + i + "").removeClass('js-displayed-search-tag-sub-category');
                        }

                        // 1件も検索候補が無い場合非表示
                        if (displaySearchTagSubCategoryCount == 0) {
                            $("#js-search-tag-sub-category-side").addClass('is-hidden');
                        }
                    }
                    // 検索タグ（サブカテゴリ）件数の取得
                    var displaySearchTagSubCategorySideItemCount = $('.js-displayed-search-tag-sub-category').length;
                    // 検索タグ（サブカテゴリ）件数を表示する
                    $('#js-search-tag-sub-category-side-heading').append('<span id="js-display-search-tag-sub-category-count">' +
                        '(' + displaySearchTagSubCategorySideItemCount + '件)</span>');
                }

                /********** 製品 **********/

                if ($(this).children(".js-check-product").val() != undefined) {

                    // 表示しない箇所を非表示にかつCSSを削除する
                    $("#js-category-side").addClass('is-hidden');
                    $("#js-category-side").removeClass('ec-productCategory__side');
                    $("#js-sub-category-side").addClass('is-hidden');
                    $("#js-sub-category-side").removeClass('ec-productCategory__side');
                    $("#js-search-tag-category-side").addClass('is-hidden');
                    $("#js-search-tag-category-side").removeClass('ec-productCategory__side');
                    $("#js-search-tag-sub-category-side").addClass('is-hidden');
                    $("#js-search-tag-sub-category-side").removeClass('ec-productCategory__side');

                    // 表示されている製品検索候補の表示非表示を判定する
                    var displayProduct = 0;
                    for (var i = 0; i < mouseOverProduct; i++) {

                        // 製品検索候補のIDを取得する
                        var productId = $('.js-check-product-side-item').val();

                        if (mouseOverProductId == productId) {

                            // 表示する箇所を表示かつCSSを追加する
                            $("#ec-headerSearchCont__side").removeClass('is-hidden');
                            $("#js-product-side").removeClass('is-hidden');
                            $("#js-product-side").addClass('ec-productCategory__side');

                            // show
                            $(".js-show-product").removeClass('is-hidden');
                            displayProduct++;
                        } else {
                            // hide
                            $(".js-show-product").addClass('is-hidden');
                        }

                        // 1件も検索候補が無い場合非表示
                        if (displayProduct == 0) {
                            $("#js-product-side").addClass('is-hidden');
                        }
                    }
                }

                // 検索表示箇所の高さ初期化（マウスオーバー後）
                $('#ec-headerSearchCont__side').css('min-height', '');
                $('#ec-headerSearchCont').css('min-height', '');

                // ヘッダー #ec-headerSearchCont__side 高さ揃え
                if (window.matchMedia('(min-width: 768px)').matches) {
                    var searchContH = $('#ec-headerSearchCont').outerHeight();
                    $('#ec-headerSearchCont__side').css('min-height', searchContH + 'px');
                    var searchContSideH = $('#ec-headerSearchCont__side').outerHeight();
                    $('#ec-headerSearchCont').css('min-height', searchContSideH + 'px');
                }
            },
            'mouseleave': function () {
                $(this).hover(
                    function () {
                        $('.ec-productCategory__item').removeClass('is-active');
                    }
                );
                $('#ec-headerSearchCont__side').on({
                    'mouseenter': function () {
                        if ($('.js-displayed-product').length == 0 &&
                            $('.js-displayed-item').length == 0 &&
                            $('.js-category-delete').length == 0 &&
                            $('.js-displayed-searchTag').length == 0
                        ) {
                            return;
                        }

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
    };

    // ページ表示時にログインチェック
    if(isWP){
        // ログイン状態をチェック
        logined(function (data){
            if(data.isLogined){
            // ログイン状態
                isLogined = true;
                $('.ec-headerNaviRole--multiple').removeClass('is-logout');
                $('.wp-login').removeClass('is-hidden');
                $('.ec-headerUser').prepend(data.firstName + ' ' + data.lastName);
                if(data.totalQuantity > 0){
                    $('.ec-cartNavi__badge').addClass('is-active');
                }
                $('.ec-cartNavi__badge').prepend(data.totalQuantity);
                if(data.isSeparatelyEstimate){
                    $('.ec-cartNavi__priceText').removeClass('is-hidden');
                } else {
                    $('.ec-cartNavi__price').prepend(data.totalPrice);
                    $('.ec-cartNavi__priceSub').removeClass('is-hidden');
                }
            } else {
            // 未ログイン状態
                $('.wp-logout').removeClass('is-hidden');
            }
        }, function (data) {
            // 未ログイン状態として処理する
            $('.wp-logout').removeClass('is-hidden');
        });
    }

    // ブラウザ内蔵パスワードマネージャー対策の為、ページ読み込み時はログイン用テキストボックスを非表示（info配下）
    if($('.ec-input.js-is-display_none').length){
        $('.ec-input.js-is-display_none').addClass('is-hidden');
    }
    // ログインボタンクリック
    $(document).on('click', '.js-loginBtn', function (e) {
        if ($('#ec-modal-login').length === 0) {
            return;
        }

        e.preventDefault();

        // ログイン状態をチェック
        logined(function (data) {
            if(data.isLogined){
            // ログイン状態
                location.reload();
            } else {
            // 未ログイン状態
                $('#ec-modal-login').prop('checked', true);
                if($('.ec-input.js-is-display_none').length){
                    $('.ec-input.js-is-display_none').removeClass('is-hidden'); // ログインボタン押下時、ログイン用テキストボックスを表示する
                }
            }
        }, function(data){
            location.reload();
        });
    })
////});

// 非同期でログインチェックを行う
function logined(successCallback,failedCallback) {
    $.ajax({
        url: uriIsLogined,
        type: 'POST',
        dataType: 'json',
    }).done(function (ret) {
        successCallback(ret);
    }).fail(function (data) {
        failedCallback(data);
    }).always(function () {
    });
}

// 仕様分類がチェックされたときのSUBMIT処理(SP版)
function specificationSpClick() {

    // インジケーター表示
    showLoader('html');

    // POSTする
    var target = document.specification_form;
    target.submit();
}

// 仕様分類全てクリア(SP版)
function allCheckClear() {
    $('.ec-refineRole').find('input[type="checkbox"]').prop('checked', false);
}

// 検索（SP用）
$(document).on('click', '.ec-refineSearch_btn', function(){ specificationSpClick(); });
// 条件クリア
$(document).on('click', '.ec-refineSearch_clearBtn', function(){ allCheckClear(); });


});
