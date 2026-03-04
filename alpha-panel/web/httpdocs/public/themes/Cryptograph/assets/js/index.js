$(function() {
    "use strict";

		  // chart1
		  var ctx = document.getElementById('chart1').getContext('2d');
		  var myChart = new Chart(ctx, {
			  type: 'line',
			  data: {
				  labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct"],
				  datasets: [{
					  label: 'New Visitor',
					  data: [3, 3, 8, 5, 7, 4, 6, 4, 6, 3],
					  backgroundColor: [
						  '#fff'
					  ],
					 fill: {
						  target: 'origin',
						  above: 'rgb(255 255 255 / 100%)',   // Area will be red above the origin
						  below: 'rgb(255 255 255 / 100%)'   // And blue below the origin
						}, 
					  tension: 0.4,
					  borderColor: [
						  '#fff'
					  ],
					  pointRadius :"0",
					  borderWidth: 3
				  },
				  {
					  label: 'Old Visitor',
					  data: [7, 5, 14, 7, 12, 6, 10, 6, 11, 5],
					  backgroundColor: [
						  'transparent'
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
					  borderWidth: 1
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
		
	

// chart2
var ctx = document.getElementById('chart2').getContext('2d');

var myChart = new Chart(ctx, {
  type: 'pie',
  data: {
      labels: ['Desktop', 'Mobile', 'Tablet'],
      datasets: [{
          data: [856, 2602, 1802, 1105],
          backgroundColor: [
		      "#ffffff",
			  "rgba(255, 255, 255, 0.50)",
			  "rgba(255, 255, 255, 0.20)"
          ],
          borderWidth: [0, 0, 0, 0]
      }]
  },
  options: {
      maintainAspectRatio: false,
      cutout: 100,
      plugins: {
      legend: {
          display: false,
      }
  }
      
  }
});



		
	// easy pie chart 
	
	 $('.easy-dash-chart1').easyPieChart({
		easing: 'easeOutBounce',
		barColor : '#fff',
		lineWidth: 10,
		trackColor : 'rgba(0, 0, 0, 0.08)',
		scaleColor: false,
		onStep: function(from, to, percent) {
			$(this.el).find('.w_percent').text(Math.round(percent));
		}
	 });	


	 $('.easy-dash-chart2').easyPieChart({
		easing: 'easeOutBounce',
		barColor : '#fff',
		lineWidth: 10,
		trackColor : 'rgba(0, 0, 0, 0.08)',
		scaleColor: false,
		onStep: function(from, to, percent) {
			$(this.el).find('.w_percent').text(Math.round(percent));
		}
	 });


	 $('.easy-dash-chart3').easyPieChart({
		easing: 'easeOutBounce',
		barColor : '#fff',
		lineWidth: 10,
		trackColor : 'rgba(0, 0, 0, 0.08)',
		scaleColor: false,
		onStep: function(from, to, percent) {
			$(this.el).find('.w_percent').text(Math.round(percent));
		}
	 });
		
		
	 
// world map
jQuery('#dashboard-map').vectorMap(
{
    map: 'world_mill_en',
    backgroundColor: 'transparent',
    borderColor: '#818181',
    borderOpacity: 0.25,
    borderWidth: 1,
    zoomOnScroll: false,
    color: '#009efb',
    regionStyle : {
        initial : {
          fill : '#fff'
        }
      },
    markerStyle: {
      initial: {
        r: 9,
        'fill': '#fff',
        'fill-opacity':1,
        'stroke': '#000',
        'stroke-width' : 5,
        'stroke-opacity': 0.4
                },
                },
    enableZoom: true,
    hoverColor: '#009efb',
    markers : [{
        latLng : [21.00, 78.00],
        name : 'Lorem Ipsum Dollar'
      
      }],
    hoverOpacity: null,
    normalizeFunction: 'linear',
    scaleColors: ['#b6d6ff', '#005ace'],
    selectedColor: '#c9dfaf',
    selectedRegions: [],
    showTooltip: true,
});
		
		
   $("#trendchart1").sparkline([5,8,7,10,9,10,8,6,4,6,8,7,6,8,9,10,8], {
      type: 'bar',
      height: '20',
      barWidth: '2',
      resize: true,
      barSpacing: '3',
      barColor: '#fff'
    });
		

	$("#trendchart2").sparkline([5,8,7,10,9,10,8,6,4,6,8,7,6,8,9,10,8], {
      type: 'bar',
      height: '20',
      barWidth: '2',
      resize: true,
      barSpacing: '3',
      barColor: '#fff'
    });


    $("#trendchart3").sparkline([5,8,7,10,9,10,8,6,4,6,8,7,6,8,9,10,8], {
      type: 'bar',
      height: '20',
      barWidth: '2',
      resize: true,
      barSpacing: '3',
      barColor: '#fff'
    });


    $("#trendchart4").sparkline([5,8,7,10,9,10,8,6,4,6,8,7,6,8,9,10,8], {
      type: 'bar',
      height: '20',
      barWidth: '2',
      resize: true,
      barSpacing: '3',
      barColor: '#fff'
    });	


     $("#trendchart5").sparkline([5,8,7,10,9,10,8,6,4,6,8,7,6,8,9,10,8], {
      type: 'bar',
      height: '20',
      barWidth: '2',
      resize: true,
      barSpacing: '3',
      barColor: '#fff'
    });	



	// chart 3
     var ctx = document.getElementById('chart3').getContext('2d');
              
       var myChart = new Chart(ctx, {
        type: 'line',
		data: {
			labels: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
			datasets: [{
				label: 'Visitors',
				data: [12, 25, 13, 25, 14, 35, 10],
				backgroundColor: [
					'#fff'
				],
				 fill: {
					target: 'origin',
					above: 'rgb(255 255 255 / 12%)',   // Area will be red above the origin
					below: 'rgb(255 255 255 / 12%)'    // And blue below the origin
				  },
				tension: 0.4,
				borderColor: [
					'#fff'
				],
				borderWidth: 3,
				pointRadius :"0",
			}]
		},
		options: {
			maintainAspectRatio: false,
			barPercentage: 0.7,
			categoryPercentage: 0.45,
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
				  display: false,
				},
				y: {
				  stacked: false,
				  beginAtZero: true,
				  display: false,
				}
			  }
		}

    
      });




      // chart 4
     var ctx = document.getElementById('chart4').getContext('2d');
              
	 var myChart = new Chart(ctx, {
	  type: 'line',
	  data: {
		  labels: ['1', '2', '3', '4', '5', '6', '7'],
		  datasets: [{
			  label: 'Clicks',
			  data: [12, 17, 8, 25, 14, 27, 15],
			  backgroundColor: [
				  '#fff'
			  ],
			    /*  fill: {
			  target: 'origin',
			 	  above: 'rgb(255 255 255 / 12%)',   
			 	  below: 'rgb(255 255 255 / 12%)'   
			 	}, */
			  tension: 0.4,
			  borderColor: [
				  '#fff'
			  ],
			  borderWidth: 3,
			  pointRadius :"0",
		  }]
	  },
	  options: {
		  maintainAspectRatio: false,
		  barPercentage: 0.7,
		  categoryPercentage: 0.45,
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
				display: false,
			  },
			  y: {
				stacked: false,
				beginAtZero: true,
				display: false,
			  }
			}
	  }

  
	});


	  
    // chart 5
	var ctx = document.getElementById("chart5").getContext('2d');
	var myChart = new Chart(ctx, {
		type: 'bar',
		data: {
			labels: ['1', '2', '3', '4', '5', '6', '7', '7', '7', '7', '7', '7'],
			datasets: [{
				label: 'Total Earning',
				data: [39, 19, 25, 16, 31, 39, 23, 20, 23, 18, 15, 20],
				backgroundColor: [
					'#fff'
				],
				borderColor: [
					'#fff'
				],
				borderWidth: 0,
				borderRadius: 20
			},
			{
				label: 'Total Sales',
				data: [27, 12, 26, 15, 21, 27, 13, 19, 32, 22, 18, 30],
				backgroundColor: [
					'rgb(255 255 255 / 25%)'
				],
				borderColor: [
					'rgb(255 255 255 / 25%)'
				],
				borderWidth: 0,
				borderRadius: 20
			}]
		},
		options: {
			maintainAspectRatio: false,
			barPercentage: 0.7,
			categoryPercentage: 0.45,
			plugins: {
				legend: {
					maxWidth: 20,
					boxHeight: 20,
					position: 'bottom',
					display: false,
				}
			},
			scales: {
				x: {
					stacked: true,
					beginAtZero: true,
					display: false,
				},
				y: {
					stacked: true,
					beginAtZero: true,
					display: false,
				}
			}
		}


	});
	
	
		
		
   });	 
   