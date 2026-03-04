$(function() {
	"use strict";

	$('#chart1').sparkline([ 1, 4, 5, 9, 8, 10, 5, 8, 4, 1, 0, 7, 5, 7, 9, 8, 10, 5], {
		type: 'bar',
		height: '40',
		barWidth: '3',
		resize: true,
		barSpacing: '4',
		barColor: '#fff',
		spotColor: '#fff',
		minSpotColor: '#fff',
		maxSpotColor: '#fff',
		highlightSpotColor: '#fff',
		highlightLineColor: '#fff'
	});
	
$("#chart2").sparkline([8,18,12,25,14,30,10], {
		type: 'line',
		width: '100',
		height: '40',
		lineWidth: '2',
		lineColor: '#fff',
		fillColor: 'transparent',
		spotColor: '#fff',
		minSpotColor: '#fff',
		maxSpotColor: '#fff',
		highlightSpotColor: '#fff',
		highlightLineColor: '#fff'
}); 	
	

	$('#chart3').sparkline([ 1, 4, 5, 9, 8, 10, 5, 8, 4, 1, 0, 7, 5, 7, 9, 8, 10, 5], {
	type: 'bar',
	height: '40',
	barWidth: '3',
	resize: true,
	barSpacing: '4',
	barColor: '#fff',
	spotColor: '#fff',
	minSpotColor: '#fff',
	maxSpotColor: '#fff',
	highlightSpotColor: '#fff',
	highlightLineColor: '#fff'
	});

				
	$("#chart4").sparkline([8,18,12,25,14,30,10], {
	type: 'line',
	width: '100',
	height: '40',
	lineWidth: '2',
	lineColor: '#fff',
	fillColor: '#fff',
	spotColor: '#fff',
	minSpotColor: '#fff',
	maxSpotColor: '#fff',
	highlightSpotColor: '#fff',
	highlightLineColor: '#fff'
	}); 	



 // chart5
 var ctx = document.getElementById('chart5').getContext('2d');
 var myChart = new Chart(ctx, {
	 type: 'line',
	 data: {
		 labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct"],
		 datasets: [{
			 label: 'New Visitor',
			 data: [3, 3, 8, 5, 7, 4, 6, 4, 6, 3],
			 backgroundColor: [
				 'rgb(255 255 255 / 100%)'
			 ],
			fill: {
				 target: 'origin',
				 above: 'rgb(255 255 255 / 100%)',   // Area will be red above the origin
				 below: 'rgb(255 255 255 / 100%)'   // And blue below the origin
			   }, 
			 tension: 0.4,
			 borderColor: [
				 'rgb(255 255 255 / 100%)'
			 ],
			 pointRadius :"0",
			 borderWidth: 3
		 },
		 {
			 label: 'Old Visitor',
			 data: [7, 5, 14, 7, 12, 6, 10, 6, 11, 5],
			 backgroundColor: [
				 'rgb(255 255 255 / 100%)'
			 ],
			 fill: {
				 target: 'origin',
				 above: 'rgb(255 255 255 / 25%)',   // Area will be red above the origin
				 below: 'rgb(255 255 255 / 25%)'    // And blue below the origin
			   },
			 tension: 0.4,
			 borderColor: [
				 'transparent'
			 ],
			 pointRadius :"0",
			 borderWidth: 2
		 }]
	 },
	 options: {
		 maintainAspectRatio: false,
		 plugins: {
			 legend: {
				 display: false,
			 }
		 },
		 scales: {
              x: {
                stacked: false,
                beginAtZero: true,
				ticks: {
                  color: "rgb(255 255 255 / 75%)", // this here
                },
				grid: {
					  display: true ,
					  color: "rgba(221, 221, 221, 0.08)"
					}
              },
              y: {
                stacked: false,
                beginAtZero: true,
				ticks: {
                  color: "rgb(255 255 255 / 75%)", // this here
                },
				grid: {
					  display: true ,
					  color: "rgba(221, 221, 221, 0.08)"
					}
              },
			  
            }
	 }
 });





// chart6
var ctx = document.getElementById('chart6').getContext('2d');

var myChart = new Chart(ctx, {
    type: 'pie',
    data: {
        labels: ['Organic', 'Direct', 'Referral', 'Others', 'Social'],
        datasets: [{
            data: [155, 120, 110, 150, 90],
            backgroundColor: [
              "rgb(255 255 255 / 100%)",
              "rgb(255 255 255 / 75%)",
              "rgb(255 255 255 / 50%)",
			  "rgb(255 255 255 / 25%)",
			  "rgb(255 255 255 / 12%)"
          ],
         borderWidth: 0
        }]
    },
    options: {
        maintainAspectRatio: false,
        cutout: 115,
        plugins: {
        legend: {
            display: false,
        }
    }
        
    }
});



   // chart7
   var ctx = document.getElementById('chart7').getContext('2d');
   var myChart = new Chart(ctx, {
	   type: 'bar',
	   data: {
		   labels: ['Chrome', 'Firefox', 'Safari', 'Opera', 'Edge', 'Mozila', 'Others'],
		   datasets: [{
			   label: 'Visits',
			   data: [60, 50, 40, 30, 20, 25, 15],
			   backgroundColor: [
				   '#fff'
			   ],
			   borderColor: [
				   '#fff'
			   ],
			   borderWidth: 0,
			   borderRadius: 0
		   }]
	   },
	   options: {
		   indexAxis: 'y',
		   maintainAspectRatio: false,
		   //barPercentage: 0.9,
		   categoryPercentage: 0.4,
		   plugins: {
			   legend: {
				   maxWidth: 20,
				   boxHeight: 20,
				   position:'bottom',
				   display: false,
			   }
		   },
		   scales: {
              x: {
                stacked: false,
                beginAtZero: true,
				ticks: {
                  color: "rgb(255 255 255 / 75%)", // this here
                },
				grid: {
					  display: true ,
					  color: "rgba(221, 221, 221, 0.0)"
					}
              },
              y: {
                stacked: false,
                beginAtZero: true,
				ticks: {
                  color: "rgb(255 255 255 / 75%)", // this here
                },
				grid: {
					  display: true ,
					  color: "rgba(221, 221, 221, 0.0)"
					}
              },
			  
            }
	   }
   });





// chart8
var ctx = document.getElementById('chart8').getContext('2d');

var gradientStroke1 = ctx.createLinearGradient(0, 0, 0, 300);
    gradientStroke1.addColorStop(0, '#005bea');
    gradientStroke1.addColorStop(1, '#00c6fb');

var gradientStroke2 = ctx.createLinearGradient(0, 0, 0, 300);
    gradientStroke2.addColorStop(0, '#ff6a00');  
    gradientStroke2.addColorStop(1, '#ee0979'); 

var gradientStroke3 = ctx.createLinearGradient(0, 0, 0, 300);
    gradientStroke3.addColorStop(0, '#17ad37');  
    gradientStroke3.addColorStop(1, '#98ec2d'); 

var myChart = new Chart(ctx, {
    type: 'pie',
    data: {
        labels: ['Desktop', 'Mobile', 'Tablet'],
        datasets: [{
            data: [155, 120, 110],
            backgroundColor: [
              "rgb(255 255 255 / 100%)",
              "rgb(255 255 255 / 50%)",
              "rgb(255 255 255 / 20%)"
          ],
            borderWidth: 0
        }]
    },
    options: {
        maintainAspectRatio: false,
        cutout: 90,
        plugins: {
        legend: {
            display: false,
        }
    }
        
    }
});









jQuery("#geographic-map").vectorMap({
	map: "world_mill_en",
	backgroundColor: "transparent",
	borderColor: "#818181",
	borderOpacity: .25,
	borderWidth: 1,
	zoomOnScroll: !1,
	color: "#009efb",
	regionStyle: {
		initial: {
			fill: "#fff"
		}
	},
	markerStyle: {
		initial: {
			r: 9,
			fill: "#fff",
			"fill-opacity": 1,
			stroke: "#000",
			"stroke-width": 5,
			"stroke-opacity": .4
		}
	},
	enableZoom: !0,
	hoverColor: "#009efb",
	markers: [{
		latLng: [21, 78],
		name: "I Love My India"
	}],
	// series: {
	// 	regions: [{
	// 		values: {
	// 			IN: "#fb6340",
	// 			US: "#15b70a",
	// 			RU: "#5e72e4",
	// 			AU: "#2dce89"
	// 		}
	// 	}]
	// },
	hoverOpacity: null,
	normalizeFunction: "linear",
	scaleColors: ["#b6d6ff", "#005ace"],
	selectedColor: "#c9dfaf",
	selectedRegions: [],
	showTooltip: !0,
	onRegionClick: function(e, t, o) {
		var r = 'You clicked "' + o + '" which has the code: ' + t.toUpperCase();
		alert(r)
	}
})




});