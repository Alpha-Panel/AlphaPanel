$(function() {


    // chart1
    var ctx = document.getElementById('chart1').getContext('2d');
    var myChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
            datasets: [{
                label: 'Google',
                data: [6, 20, 14, 12, 17, 8, 10],
                backgroundColor: [
                    '#fff'
                ],
                lineTension: 0.4,
                borderColor: [
                    '#fff'
                ],
                borderWidth: 3
            },
            {
                label: 'Facebook',
                data: [5, 30, 16, 23, 8, 14, 11],
                backgroundColor: [
                    'rgb(255 255 255 / 25%)'
                ],
                tension: 0.4,
                borderColor: [
                    'rgb(255 255 255 / 25%)'
                ],
                borderWidth: 3
            }]
        },
        options: {
            maintainAspectRatio: false,
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
        type: 'bar',
        data: {
            labels: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
            datasets: [{
                label: 'Google',
                data: [18, 25, 14, 12, 17, 8, 10],
                backgroundColor: [
                    '#fff'
                ],
                lineTension: 0,
                borderColor: [
                    '#fff'
                ],
                borderWidth: 3
            },
            {
                label: 'Facebook',
                data: [12, 30, 16, 23, 8, 14, 11],
                backgroundColor: [
                    'rgb(255 255 255 / 25%)'
                ],
                tension: 0,
                borderColor: [
                    'transparent'
                ],
                borderWidth: 0
            }]
        },
        options: {
            maintainAspectRatio: false,
            barPercentage: 0.6,
            categoryPercentage: 0.5,
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


     // chart3
     var ctx = document.getElementById('chart3').getContext('2d');
     var myChart = new Chart(ctx, {
         type: 'pie',
         data: {
             labels: ['label 1', 'label 2', 'label 3', 'label 4', 'label 5'],
             datasets: [{
                 data: [12, 19, 3, 5, 2],
                 backgroundColor: [
					  "rgb(255 255 255 / 100%)",
					  "rgb(255 255 255 / 75%)",
					  "rgb(255 255 255 / 50%)",
					  "rgb(255 255 255 / 25%)"
				  ],
                 borderWidth: 0
             }]
         },
         options: {
            maintainAspectRatio: false,
            plugins: {
				legend: {
					position:'bottom',
					display: true,
					labels: {
					  color: 'rgb(255 255 255 / 75%)'
				   }
				}
			},

         }
     });



      // chart4
      var ctx = document.getElementById('chart4').getContext('2d');
      var myChart = new Chart(ctx, {
          type: 'doughnut',
           data: {
             labels: ['label 1', 'label 2', 'label 3', 'label 4', 'label 5'],
             datasets: [{
                 data: [12, 19, 3, 5, 2],
                 backgroundColor: [
					  "rgb(255 255 255 / 100%)",
					  "rgb(255 255 255 / 75%)",
					  "rgb(255 255 255 / 50%)",
					  "rgb(255 255 255 / 25%)"
				  ],
                 borderWidth: 0
             }]
         },
         options: {
            maintainAspectRatio: false,
            plugins: {
				legend: {
					position:'bottom',
					display: true,
					labels: {
					  color: 'rgb(255 255 255 / 75%)'
				   }
				}
			},

         }
      });




      // chart5
      var ctx = document.getElementById('chart5').getContext('2d');
      var myChart = new Chart(ctx, {
          type: 'radar',
          data: {
            labels: [
                'Eating',
                'Drinking',
                'Sleeping',
                'Designing',
                'Coding',
                'Cycling',
                'Running'
              ],
              datasets: [{
                label: 'My First Dataset',
                data: [65, 59, 90, 81, 56, 55, 40],
                fill: true,
                backgroundColor: 'rgb(255 255 255 / 12%)',
                borderColor: 'rgb(255 255 255 / 75%)',
                pointBackgroundColor: 'rgb(255 255 255 / 100%)',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgb(255 255 255 / 100%)'
              }, {
                label: 'My Second Dataset',
                data: [28, 48, 40, 19, 96, 27, 100],
                fill: true,
                backgroundColor: 'rgb(255 255 255 / 100%)',
                borderColor: 'rgb(255 255 255 / 75%)',
                pointBackgroundColor: 'rgb(255 255 255 / 100%)',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgb(255 255 255 / 100%)'
              }]
          },
          options: {
            maintainAspectRatio: false,
            plugins: {
				legend: {
					position:'bottom',
					display: true,
					labels: {
					  color: 'rgb(255 255 255 / 75%)'
				   }
				}
			},
            elements: {
              line: {
                borderWidth: 3
              }
            },
			scales: {
			  r: {
				angleLines: {
				  color: 'rgb(255 255 255 / 12%)'
				},
				grid: {
				  color: 'rgb(255 255 255 / 12%)'
				},
				 pointLabels: {
				   color: 'rgb(255 255 255 / 100%)'
				}
			  }
			}

          },
      });




      // chart6
      var ctx = document.getElementById('chart6').getContext('2d');
      var myChart = new Chart(ctx, {
          type: 'polarArea',
          data: {
            labels: [
                'Red',
                'Purple',
                'Yellow',
                'Grey'
              ],
              datasets: [{
                label: 'My First Dataset',
                data: [11, 16, 7, 3],
                 backgroundColor: [
					  "rgb(255 255 255 / 100%)",
					  "rgb(255 255 255 / 75%)",
					  "rgb(255 255 255 / 50%)",
					  "rgb(255 255 255 / 25%)"
				  ],
              }]
          },
          options: {
            maintainAspectRatio: false,
            plugins: {
				legend: {
					position:'bottom',
					display: true,
					labels: {
					  color: 'rgb(255 255 255 / 75%)'
				   }
				}
			},
            elements: {
              line: {
                borderWidth: 3
              }
            },
			scales: {
			  r: {
				angleLines: {
				  color: 'rgb(255 255 255 / 12%)'
				},
				grid: {
				  color: 'rgb(255 255 255 / 12%)'
				},
				 pointLabels: {
				   color: 'rgb(255 255 255 / 100%)'
				}
			  }
			}

          },
      });




});
