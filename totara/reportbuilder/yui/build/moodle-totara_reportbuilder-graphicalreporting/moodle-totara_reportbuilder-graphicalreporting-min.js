YUI.add("moodle-totara_reportbuilder-graphicalreporting",function(e,t){M.reportbuilder=M.reportbuilder||{},NS=M.reportbuilder.graphicalreport=M.reportbuilder.graphicalreport||{},NS.init=function(){$("form").on("change","#id_type",this.handletypechange),$("form").on("change","#id_orientation",this.handleorientationchange),$("#id_type").change(),$("#id_orientation").change(),$(".fitem").css("overflow","hidden")},NS.handletypechange=function(e){var t=$(e.currentTarget).val();t==="pie"?$("#id_series").removeAttr("multiple"):$("#id_series").attr("multiple",1),t==="pie"||t==="scatter"?($("#fitem_id_stacked").hide(),$("#fitem_id_orientation").show(),$("#id_serieshdr").show(),$("#id_advancedhdr").show()):t===""?($("#fitem_id_orientation").hide(),$("#id_serieshdr").hide(),$("#id_advancedhdr").hide()):($("#fitem_id_stacked").show(),$("#fitem_id_orientation").show(),$("#id_serieshdr").show(),$("#id_advancedhdr").show()),e.preventDefault()},NS.handleorientationchange=function(e){var t=$(e.currentTarget).val();t==="C"?($("#fitem_id_category").show(),$("#fitem_id_legend").hide()):($("#fitem_id_category").hide(),$("#fitem_id_legend").show())}},"@VERSION@",{requires:[]});
