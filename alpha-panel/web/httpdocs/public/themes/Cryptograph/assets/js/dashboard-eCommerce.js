$(function() {
    "use strict";


  // chart1
  var ctx = document.getElementById('chart1').getContext('2d');

  var myChart = new Chart(ctx, {
      type: 'bar',
      data: {
          labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
          datasets: [{
              label: 'Page Views',
              
			  data: [20, 35, 30, 35, 28, 22, 25],
              backgroundColor: [
                  "#fff"
              ],
              borderColor: [
                  "#fff"
              ],
              borderWidth: 0,
              borderRadius: 20
          },
          {
              label: 'Sales',
              data: [15, 22, 13, 25, 20, 10, 15],
              backgroundColor: [
                  "rgb(255 255 255 / 50%)"
              ],
              borderColor: [
                  "rgb(255 255 255 / 50%)"
              ],
              borderWidth: 0,
              borderRadius: 20
          },{
              label: 'Conversion',
              data: [10, 15, 9, 15, 17, 16, 10],
              backgroundColor: [
                  "rgb(255 255 255 / 25%)"
              ],
              borderColor: [
                  "rgb(255 255 255 / 25%)"
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
                  position:'bottom',
                  display: true,
				   labels: {
					color: 'rgb(255 255 255 / 75%)'
				  }
				  
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
          data: [155, 120, 110],
          backgroundColor: [
              "rgb(255 255 255 / 100%)",
              "rgb(255 255 255 / 50%)",
              "rgb(255 255 255 / 25%)"
          ],
          borderWidth: 0
      }]
  },
  options: {
      maintainAspectRatio: false,
      cutout: 110,
      plugins: {
      legend: {
          display: false,
      }
  }
      
  }
});





// chart3
var ctx = document.getElementById('chart3').getContext('2d');

var myChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
        datasets: [{
            label: 'Visitors',
            data: [12, 25, 13, 25, 14, 35, 10],
            backgroundColor: [
                "rgb(255 255 255 / 100%)",
            ],
             fill: {
                target: 'origin',
                above: 'rgb(255 255 255 / 12%)',   // Area will be red above the origin
                below: 'rgb(255 255 255 / 12%)'    // And blue below the origin
              },
            tension: 0.4,
            borderColor: [
                "rgb(255 255 255 / 100%)",
            ],
            borderWidth: 4
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



   //donut

   $("span.donut").peity("donut",{
    width: 130,
    height: 130 
  });
    

   });