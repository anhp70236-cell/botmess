(function(){
  var P="@", T="ios_b", i=0, dir=1;
  function step(){
    document.title = P + T.slice(0,i);
    var d=250;
    if(dir===1){ if(i===T.length){dir=-1;d=1500}else{i++} }
    else{ if(i===0){dir=1;d=500;i=1}else{i--;d=150} }
    setTimeout(step,d);
  }
  step();
})();
