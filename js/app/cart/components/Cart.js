import React, { Component } from 'react'
import { connect } from 'react-redux'
import { withTranslation } from 'react-i18next'
import Sticky from 'react-stickynode'

import AddressModal from './AddressModal'
import DateModal from './DateModal'
import RestaurantModal from './RestaurantModal'
import AddressAutosuggest from '../../components/AddressAutosuggest'
import CartErrors from './CartErrors'
import CartItems from './CartItems'
import CartHeading from './CartHeading'
import CartTotal from './CartTotal'
import CartButton from './CartButton'
import Time from './Time'

import { changeAddress, sync } from '../redux/actions'

let isXsDevice = $('.visible-xs').is(':visible')

class Cart extends Component {

  componentDidMount() {
    this.props.sync()
  }

  render() {

    const { isMobileCartVisible } = this.props

    const panelClasses = ['panel', 'panel-default', 'cart-wrapper']
    if (isMobileCartVisible) {
      panelClasses.push('cart-wrapper--show')
    }

    return (
      <Sticky enabled={ !isXsDevice }>
        <div className={ panelClasses.join(' ') }>
          <CartHeading />
          <div className="panel-body">
            <CartErrors />
            <div className="cart">
              <AddressAutosuggest
                addresses={ this.props.addresses }
                address={ this.props.streetAddress }
                geohash={ '' }
                key={ this.props.streetAddress }
                onAddressSelected={ (value, address) => this.props.changeAddress(address) } />
              <Time />
              <CartItems />
              <CartTotal />
              <CartButton />
            </div>
          </div>
        </div>
        <AddressModal />
        <RestaurantModal />
        <DateModal />
      </Sticky>
    )
  }
}

function mapStateToProps(state) {

  return {
    shippingAddress: state.cart.shippingAddress,
    streetAddress: state.cart.shippingAddress ? state.cart.shippingAddress.streetAddress : '',
    isMobileCartVisible: state.isMobileCartVisible,
    addresses: state.addresses,
  }
}

function mapDispatchToProps(dispatch) {

  return {
    changeAddress: address => dispatch(changeAddress(address)),
    sync: () => dispatch(sync()),
  }
}

export default connect(mapStateToProps, mapDispatchToProps)(withTranslation()(Cart))
